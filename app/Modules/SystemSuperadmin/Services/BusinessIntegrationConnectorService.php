<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Modules\SystemSuperadmin\Models\BusinessIntegrationConnector;

class BusinessIntegrationConnectorService
{
    public function enabled(): bool
    {
        return (bool) data_get(ActiveBusinessProfile::payload(), 'capabilities.uses_integrations', false)
            && (bool) data_get(ActiveBusinessProfile::payload(), 'feature_flags.integrations_engine', false)
            && (bool) data_get(ActiveBusinessProfile::payload(), 'integrations.enabled', false);
    }

    /**
     * @return array<int, BusinessIntegrationConnector>
     */
    public function connectorsFor(string $channel, ?int $branchId = null): array
    {
        if (! $this->enabled() || ! $this->channelEnabled($channel)) {
            return [];
        }

        return BusinessIntegrationConnector::query()
            ->with('branch:id,name')
            ->where('channel', $channel)
            ->where('is_active', true)
            ->when($branchId, fn ($query) => $query->where(fn ($scope) => $scope->whereNull('branch_id')->orWhere('branch_id', $branchId)))
            ->orderByRaw('branch_id is null')
            ->orderBy('name')
            ->get()
            ->all();
    }

    public function resolve(string $code, ?int $branchId = null): ?BusinessIntegrationConnector
    {
        if (! $this->enabled()) {
            return null;
        }

        $connector = BusinessIntegrationConnector::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->when($branchId, fn ($query) => $query->where(fn ($scope) => $scope->whereNull('branch_id')->orWhere('branch_id', $branchId)))
            ->orderByRaw('branch_id is null')
            ->first();

        if (! $connector || ! $this->channelEnabled($connector->channel)) {
            return null;
        }

        return $connector;
    }

    public function channelEnabled(string $channel): bool
    {
        return (bool) data_get(ActiveBusinessProfile::payload(), "integrations.channels.{$channel}", false);
    }

    /**
     * @return array<string, mixed>
     */
    public function secretConfig(BusinessIntegrationConnector $connector): array
    {
        if (! $this->enabled() || ! $this->channelEnabled($connector->channel)) {
            return [];
        }

        return $connector->decodedSecretConfig();
    }
}
