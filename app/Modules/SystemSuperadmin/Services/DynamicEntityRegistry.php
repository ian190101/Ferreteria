<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Modules\SystemSuperadmin\Models\DynamicEntity;
use App\Support\SystemCacheInvalidator;
use Illuminate\Support\Facades\Cache;

class DynamicEntityRegistry
{
    /**
     * @return array<string, string>
     */
    public function options(bool $includeInactive = false): array
    {
        return Cache::remember($this->cacheKey('options', [$includeInactive ? 'all' : 'usable']), $this->cacheTtl(), function () use ($includeInactive): array {
            $baseEntities = BusinessTransversalConfiguration::baseEntities();
            $dynamicEntities = DynamicEntity::query()
                ->when(! $includeInactive, fn ($query) => $query->where('is_active', true)->where('is_visible', true))
                ->orderBy('sort_order')
                ->orderBy('label')
                ->pluck('label', 'code')
                ->all();

            return array_replace($baseEntities, $dynamicEntities);
        });
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

        return Cache::remember($this->cacheKey('usable', [$entityType]), $this->cacheTtl(), fn (): bool => DynamicEntity::query()
            ->where('code', $entityType)
            ->where('is_active', true)
            ->where('is_visible', true)
            ->exists());
    }

    private function cacheKey(string $scope, array $parts = []): string
    {
        return 'business-dynamic-entities:'.SystemCacheInvalidator::operationalVersion().':'.$scope.':'.implode(':', $parts);
    }

    private function cacheTtl(): \DateTimeInterface
    {
        $minutes = (int) data_get(ActiveBusinessProfile::payload(), 'performance.cache_profile_minutes', 30);

        return now()->addMinutes(max($minutes, 1));
    }
}
