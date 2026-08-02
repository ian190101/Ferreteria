<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Modules\SystemSuperadmin\Models\BusinessCommercialFlowRule;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class BusinessCommercialFlowRuleService
{
    /**
     * @return Collection<int, BusinessCommercialFlowRule>
     */
    public function rulesFor(?string $salesWorkflow = null, ?string $channel = null, bool $onlyRuntimeEnabled = true): Collection
    {
        if ($onlyRuntimeEnabled && ! $this->runtimeEnabled()) {
            return collect();
        }

        return BusinessCommercialFlowRule::query()
            ->where('is_active', true)
            ->when($salesWorkflow, fn ($query) => $query->where('sales_workflow', $salesWorkflow))
            ->when($channel, fn ($query) => $query->where(function ($nested) use ($channel): void {
                $nested->where('channel', $channel)->orWhereNull('channel');
            }))
            ->orderByRaw('channel is null')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    public function resolve(string $code, bool $onlyRuntimeEnabled = true): ?BusinessCommercialFlowRule
    {
        if ($onlyRuntimeEnabled && ! $this->runtimeEnabled()) {
            return null;
        }

        return BusinessCommercialFlowRule::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->first();
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, string>
     */
    public function validateContext(BusinessCommercialFlowRule|string $rule, array $context): array
    {
        $resolved = is_string($rule) ? $this->resolve($rule) : $rule;

        if (! $resolved) {
            throw ValidationException::withMessages([
                'commercial_flow' => 'La regla comercial no existe o el motor comercial/POS no esta activo.',
            ]);
        }

        $errors = [];

        if ($resolved->customer_mode === 'required' && blank($context['customer_id'] ?? null)) {
            $errors[] = 'Este flujo requiere cliente antes de continuar.';
        }

        if ($resolved->cash_policy === 'required' && ! (bool) ($context['cash_session_open'] ?? false)) {
            $errors[] = 'Este flujo requiere caja abierta.';
        }

        if ($resolved->requires_resource && blank($context['resource_id'] ?? null)) {
            $errors[] = 'Este flujo requiere un recurso asignado.';
        }

        if ($resolved->requires_responsible && blank($context['responsible_id'] ?? null)) {
            $errors[] = 'Este flujo requiere responsable, tecnico, mesero o vendedor asignado.';
        }

        if ($resolved->requires_reservation && blank($context['reservation_id'] ?? null)) {
            $errors[] = 'Este flujo requiere reserva vinculada.';
        }

        if ($resolved->requires_service_order && blank($context['service_order_id'] ?? null)) {
            $errors[] = 'Este flujo requiere orden de servicio vinculada.';
        }

        if ($resolved->requires_guarantee && blank($context['guarantee_id'] ?? null)) {
            $errors[] = 'Este flujo requiere garantia vinculada.';
        }

        if (! $resolved->allows_discount && (float) ($context['discount_amount'] ?? 0) > 0) {
            $errors[] = 'Este flujo no permite descuentos.';
        }

        if (! $resolved->allows_price_override && (bool) ($context['has_price_override'] ?? false)) {
            $errors[] = 'Este flujo no permite cambio manual de precio.';
        }

        if (! $resolved->allows_credit && (float) ($context['credit_amount'] ?? 0) > 0) {
            $errors[] = 'Este flujo no permite credito.';
        }

        if (! $resolved->allows_advance && (float) ($context['advance_amount'] ?? 0) > 0) {
            $errors[] = 'Este flujo no permite anticipos.';
        }

        $payments = $context['payments'] ?? [];
        if (is_array($payments)) {
            if (! $resolved->allows_split_payment && count($payments) > 1) {
                $errors[] = 'Este flujo no permite pago dividido.';
            }

            $methods = collect($payments)
                ->map(fn ($payment) => is_array($payment) ? ($payment['method'] ?? null) : null)
                ->filter()
                ->unique()
                ->values();

            if (! $resolved->allows_mixed_payment && $methods->count() > 1) {
                $errors[] = 'Este flujo no permite pago mixto.';
            }
        }

        return $errors;
    }

    public function runtimeEnabled(): bool
    {
        return (bool) data_get(ActiveBusinessProfile::payload(), 'feature_flags.commercial_flow_engine', false)
            && ((bool) data_get(ActiveBusinessProfile::payload(), 'capabilities.uses_commercial_flow_rules', false)
                || (bool) data_get(ActiveBusinessProfile::payload(), 'capabilities.uses_dynamic_pos', false));
    }
}
