<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Models\User;
use App\Modules\SystemSuperadmin\Models\OperationalTrace;
use Illuminate\Database\Eloquent\Model;

class OperationalTraceService
{
    public function enabled(): bool
    {
        return (bool) data_get(ActiveBusinessProfile::payload(), 'capabilities.uses_operational_traceability', false)
            && (bool) data_get(ActiveBusinessProfile::payload(), 'feature_flags.operational_traceability_engine', false)
            && (bool) data_get(ActiveBusinessProfile::payload(), 'traceability.enabled', false);
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $context
     */
    public function record(
        string $entityType,
        ?int $entityId,
        string $event,
        ?User $actor = null,
        ?int $branchId = null,
        array $metadata = [],
        array $context = [],
        string $source = 'system',
        string $status = 'completed',
    ): ?OperationalTrace {
        if (! $this->enabled()) {
            return null;
        }

        return OperationalTrace::query()->create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'event' => $event,
            'actor_id' => $actor?->id,
            'branch_id' => $branchId ?? $actor?->branch_id,
            'source' => $source,
            'status' => $status,
            'metadata' => $metadata,
            'context' => $context,
            'occurred_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $context
     */
    public function recordModel(
        Model $model,
        string $event,
        ?User $actor = null,
        ?int $branchId = null,
        array $metadata = [],
        array $context = [],
        string $source = 'system',
        string $status = 'completed',
    ): ?OperationalTrace {
        return $this->record(
            $this->entityTypeFor($model),
            (int) $model->getKey(),
            $event,
            $actor,
            $branchId,
            $metadata,
            $context,
            $source,
            $status,
        );
    }

    private function entityTypeFor(Model $model): string
    {
        $class = class_basename($model);
        $snake = preg_replace('/(?<!^)[A-Z]/', '_$0', $class) ?: $class;

        return strtolower($snake);
    }
}
