<?php

namespace App\Modules\SystemSuperadmin\Services;

class BusinessProfilePolicyService
{
    public function moduleEnabled(string $module): bool
    {
        return ActiveBusinessProfile::enabled($module);
    }

    public function capabilityEnabled(string $capability): bool
    {
        return ActiveBusinessProfile::capable($capability);
    }

    public function dataPolicy(string $key, mixed $default = null): mixed
    {
        return data_get(ActiveBusinessProfile::payload(), 'policies.data.'.$key, $default);
    }

    public function documentNumbering(string $key, mixed $default = null): mixed
    {
        return data_get(ActiveBusinessProfile::payload(), 'documents.numbering.'.$key, $default);
    }

    public function voidReturnPolicy(string $key, mixed $default = null): mixed
    {
        return data_get(ActiveBusinessProfile::payload(), 'policies.voids_returns.'.$key, $default);
    }

    public function degradedMode(string $key, mixed $default = null): mixed
    {
        return data_get(ActiveBusinessProfile::payload(), 'policies.degraded_mode.'.$key, $default);
    }

    public function activeDocuments(): array
    {
        return (array) data_get(ActiveBusinessProfile::payload(), 'documents.active', []);
    }

    public function shouldBlockNewRecordsForInactiveModule(string $module): bool
    {
        return ! $this->moduleEnabled($module)
            && (bool) $this->degradedMode('block_new_records', true);
    }

    public function allowsHistoricalReadForInactiveModule(string $module): bool
    {
        return ! $this->moduleEnabled($module)
            && (bool) $this->degradedMode('inactive_modules_read_only_history', true);
    }
}
