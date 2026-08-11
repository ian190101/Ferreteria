<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Models\User;
use App\Modules\SystemSuperadmin\Models\BranchOperationPolicy;
use App\Support\BranchAccess;

class AdvancedBranchPolicyService
{
    public function enabled(): bool
    {
        return (bool) data_get(ActiveBusinessProfile::payload(), 'capabilities.uses_advanced_multibranch', false)
            && (bool) data_get(ActiveBusinessProfile::payload(), 'feature_flags.advanced_multibranch_engine', false)
            && (bool) data_get(ActiveBusinessProfile::payload(), 'multibranch.enabled', false);
    }

    /**
     * @return array<int, BranchOperationPolicy>
     */
    public function policiesFor(string $operation, ?int $branchId = null): array
    {
        if (! $this->enabled()) {
            return [];
        }

        return BranchOperationPolicy::query()
            ->with('branch:id,name')
            ->where('operation', $operation)
            ->where('is_active', true)
            ->when($branchId, fn ($query) => $query->where(fn ($scope) => $scope->whereNull('branch_id')->orWhere('branch_id', $branchId)))
            ->orderByRaw('branch_id is null')
            ->orderBy('name')
            ->get()
            ->all();
    }

    public function policyFor(string $operation, ?int $branchId = null): ?BranchOperationPolicy
    {
        return $this->policiesFor($operation, $branchId)[0] ?? null;
    }

    /**
     * @return array{allowed: bool, requires_approval: bool, errors: array<int, string>, policy_id: int|null}
     */
    public function branchDecision(User $user, int $branchId, string $operation): array
    {
        if (! $this->enabled()) {
            return [
                'allowed' => BranchAccess::canAccess($user, $branchId),
                'requires_approval' => false,
                'errors' => BranchAccess::canAccess($user, $branchId) ? [] : ['No tienes acceso a esta sucursal.'],
                'policy_id' => null,
            ];
        }

        $policy = $this->policyFor($operation, $branchId);
        $errors = [];

        if ((bool) data_get($policy?->rules, 'require_user_branch_access', true) && ! BranchAccess::canAccess($user, $branchId)) {
            $errors[] = 'No tienes acceso a esta sucursal.';
        }

        $allowedBranchIds = collect((array) data_get($policy?->rules, 'allowed_branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();

        if ($allowedBranchIds !== [] && ! in_array($branchId, $allowedBranchIds, true)) {
            $errors[] = 'La politica avanzada no permite operar esta sucursal.';
        }

        return $this->decisionFromPolicy($policy, $errors);
    }

    /**
     * @return array{allowed: bool, requires_approval: bool, errors: array<int, string>, policy_id: int|null}
     */
    public function transferDecision(User $user, int $fromBranchId, int $toBranchId, float $quantity): array
    {
        if ($fromBranchId === $toBranchId) {
            return [
                'allowed' => false,
                'requires_approval' => false,
                'errors' => ['La sucursal origen y destino deben ser distintas.'],
                'policy_id' => null,
            ];
        }

        if (! $this->enabled()) {
            $canAccess = BranchAccess::canAccess($user, $fromBranchId) && BranchAccess::canAccess($user, $toBranchId);

            return [
                'allowed' => $canAccess,
                'requires_approval' => false,
                'errors' => $canAccess ? [] : ['No tienes acceso a una de las sucursales de la transferencia.'],
                'policy_id' => null,
            ];
        }

        $policy = $this->policyFor('transfer_inventory', $fromBranchId);
        $errors = [];

        if ((bool) data_get($policy?->rules, 'require_user_branch_access', true)) {
            if (! BranchAccess::canAccess($user, $fromBranchId) || ! BranchAccess::canAccess($user, $toBranchId)) {
                $errors[] = 'No tienes acceso a una de las sucursales de la transferencia.';
            }
        }

        if (! (bool) data_get($policy?->rules, 'allow_cross_branch', true)) {
            $errors[] = 'La politica avanzada no permite transferencias entre sucursales.';
        }

        $allowedDestinationIds = collect((array) data_get($policy?->rules, 'allowed_destination_branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();

        if ($allowedDestinationIds !== [] && ! in_array($toBranchId, $allowedDestinationIds, true)) {
            $errors[] = 'La sucursal destino no esta permitida para esta politica.';
        }

        $maxQuantity = data_get($policy?->rules, 'max_transfer_quantity');
        if (is_numeric($maxQuantity) && $quantity > (float) $maxQuantity) {
            $errors[] = 'La cantidad supera el limite configurado para transferencias.';
        }

        return $this->decisionFromPolicy($policy, $errors);
    }

    /**
     * @param array<int, string> $errors
     * @return array{allowed: bool, requires_approval: bool, errors: array<int, string>, policy_id: int|null}
     */
    private function decisionFromPolicy(?BranchOperationPolicy $policy, array $errors): array
    {
        $mode = $policy?->enforcement_mode ?? BranchOperationPolicy::ENFORCEMENT_MONITOR;
        $hasErrors = $errors !== [];

        return [
            'allowed' => ! $hasErrors || $mode === BranchOperationPolicy::ENFORCEMENT_MONITOR,
            'requires_approval' => $hasErrors && $mode === BranchOperationPolicy::ENFORCEMENT_APPROVAL_REQUIRED,
            'errors' => $errors,
            'policy_id' => $policy?->id,
        ];
    }
}
