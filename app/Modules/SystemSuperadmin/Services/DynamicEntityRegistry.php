<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Modules\SystemSuperadmin\Models\DynamicEntity;

class DynamicEntityRegistry
{
    /**
     * @return array<string, string>
     */
    public function options(bool $includeInactive = false): array
    {
        $baseEntities = BusinessTransversalConfiguration::baseEntities();
        $dynamicEntities = DynamicEntity::query()
            ->when(! $includeInactive, fn ($query) => $query->where('is_active', true)->where('is_visible', true))
            ->orderBy('sort_order')
            ->orderBy('label')
            ->pluck('label', 'code')
            ->all();

        return array_replace($baseEntities, $dynamicEntities);
    }

    public function isKnown(string $entityType): bool
    {
        return array_key_exists($entityType, $this->options(true));
    }

    public function isUsable(string $entityType): bool
    {
        if (array_key_exists($entityType, BusinessTransversalConfiguration::baseEntities())) {
            return true;
        }

        return DynamicEntity::query()
            ->where('code', $entityType)
            ->where('is_active', true)
            ->where('is_visible', true)
            ->exists();
    }
}
