<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Models\User;
use App\Modules\SystemSuperadmin\Models\BusinessStateDefinition;

class BusinessStateTransitionService
{
    public function canTransition(string $entityType, string $fromCode, string $toCode, ?User $user = null): bool
    {
        $from = BusinessStateDefinition::query()
            ->where('entity_type', $entityType)
            ->where('code', $fromCode)
            ->where('is_active', true)
            ->first();

        $to = BusinessStateDefinition::query()
            ->where('entity_type', $entityType)
            ->where('code', $toCode)
            ->where('is_active', true)
            ->first();

        if (! $from || ! $to) {
            return false;
        }

        $allowed = $from->allowed_transitions ?? [];

        if ($allowed !== [] && ! in_array($toCode, $allowed, true)) {
            return false;
        }

        return blank($to->required_permission) || $user?->can($to->required_permission);
    }

    /**
     * @return array<int, BusinessStateDefinition>
     */
    public function initialStates(string $entityType): array
    {
        return BusinessStateDefinition::query()
            ->where('entity_type', $entityType)
            ->where('is_initial', true)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->all();
    }
}
