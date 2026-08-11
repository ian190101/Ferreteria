<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\SystemSuperadmin\Models\DynamicEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DataGovernanceService
{
    public function enabled(): bool
    {
        return (bool) data_get(ActiveBusinessProfile::payload(), 'capabilities.uses_data_governance', false)
            && (bool) data_get(ActiveBusinessProfile::payload(), 'feature_flags.data_governance_engine', false);
    }

    public function shouldSoftDelete(): bool
    {
        return (bool) data_get(ActiveBusinessProfile::payload(), 'governance.soft_delete_by_default', true);
    }

    public function canExportSensitive(?User $user): bool
    {
        if (! $this->sensitiveExportRequiresPermission()) {
            return true;
        }

        return (bool) $user?->can('exports.sensitive');
    }

    public function canExportEntity(string $entityType, ?User $user): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        if (! $this->isSensitiveEntity($entityType)) {
            return true;
        }

        return $this->canExportSensitive($user);
    }

    public function anonymizationAllowed(string $entityType): bool
    {
        return $this->enabled()
            && (bool) data_get(ActiveBusinessProfile::payload(), 'governance.allow_anonymization', false)
            && in_array($entityType, ['customer'], true);
    }

    public function anonymizeCustomer(Customer $customer, User $actor, string $reason): Customer
    {
        if (! $this->anonymizationAllowed('customer')) {
            throw ValidationException::withMessages([
                'governance' => 'El perfil activo no permite anonimizar clientes.',
            ]);
        }

        if (! $actor->can('data-governance.manage')) {
            throw ValidationException::withMessages([
                'governance' => 'No tienes permiso para anonimizar datos personales.',
            ]);
        }

        return DB::transaction(function () use ($customer, $actor, $reason): Customer {
            $customer->update([
                'name' => 'Cliente anonimizado #'.$customer->id,
                'document_number' => null,
                'phone' => null,
                'email' => null,
                'address' => null,
                'is_active' => false,
            ]);

            $customer->interactions()->create([
                'user_id' => $actor->id,
                'type' => 'data_governance',
                'status' => 'completed',
                'contact_at' => now(),
                'subject' => 'Anonimizacion de datos personales',
                'notes' => "Anonimizacion ejecutada por {$actor->name}. Motivo: {$reason}",
                'completed_at' => now(),
            ]);

            app(OperationalTraceService::class)->recordModel(
                $customer,
                'customer_anonymized',
                $actor,
                $customer->branch_id,
                [
                    'reason' => $reason,
                    'entity_type' => 'customer',
                ],
                [],
                'data_governance',
            );

            return $customer->refresh();
        });
    }

    public function retentionPolicyFor(string $entityType): string
    {
        $entity = DynamicEntity::query()
            ->where('code', $entityType)
            ->where('is_active', true)
            ->first();

        return $entity?->retention_policy
            ?? (string) data_get(ActiveBusinessProfile::payload(), 'governance.retention_policy', 'manual');
    }

    public function isSensitiveEntity(string $entityType): bool
    {
        if (in_array($entityType, (array) data_get(ActiveBusinessProfile::payload(), 'governance.sensitive_entities', []), true)) {
            return true;
        }

        return (bool) DynamicEntity::query()
            ->where('code', $entityType)
            ->where('is_active', true)
            ->where('is_sensitive', true)
            ->exists();
    }

    public function deactivateOrDelete(Model $model): void
    {
        if (array_key_exists('is_active', $model->getAttributes())) {
            $model->update(['is_active' => false]);
            return;
        }

        $model->delete();
    }

    private function sensitiveExportRequiresPermission(): bool
    {
        return (bool) data_get(ActiveBusinessProfile::payload(), 'governance.export_sensitive_requires_permission', true);
    }
}
