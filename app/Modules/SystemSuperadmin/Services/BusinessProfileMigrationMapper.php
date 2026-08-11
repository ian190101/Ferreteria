<?php

namespace App\Modules\SystemSuperadmin\Services;

class BusinessProfileMigrationMapper
{
    public function toVersionTwo(string $businessType, array $configuration): array
    {
        $explicitCapabilities = $configuration['capabilities'] ?? [];
        $configuration = BusinessProfileConfiguration::normalized($configuration);
        $configuration['schema_version'] = 2;
        $configuration['identity']['business_type'] = $businessType;

        $configuration['capabilities'] = array_replace(
            $this->capabilitiesFromLegacy($businessType, $configuration),
            $explicitCapabilities,
        );

        return BusinessProfileConfiguration::normalized($configuration);
    }

    private function capabilitiesFromLegacy(string $businessType, array $configuration): array
    {
        $modules = $configuration['modules'] ?? [];
        $sales = $configuration['sales'] ?? [];
        $cash = $configuration['cash'] ?? [];
        $billing = $configuration['billing'] ?? [];

        return [
            'uses_inventory' => (bool) ($modules['inventory'] ?? false),
            'uses_pos' => (bool) ($modules['pos'] ?? false),
            'uses_barcode_scanner' => ($configuration['pos']['scanner_mode'] ?? 'disabled') !== 'disabled',
            'uses_cash' => (bool) ($modules['cash'] ?? false),
            'uses_cash_flow' => (bool) ($modules['cash_flow'] ?? false),
            'requires_cash_session' => (bool) ($cash['required_to_sell'] ?? false),
            'uses_banks' => (bool) ($modules['banks'] ?? false),
            'uses_billing' => (bool) (($modules['billing'] ?? false) || ($billing['enabled'] ?? false)),
            'requires_customer' => ($sales['customer_mode'] ?? 'required') === 'required',
            'allows_direct_sale' => in_array($sales['workflow'] ?? '', ['direct_sale', 'pos', 'optional_quotation'], true),
            'allows_quote_to_sale' => (bool) ($modules['quotes'] ?? false),
            'uses_delivery' => (bool) ($modules['deliveries'] ?? false),
            'uses_services' => $businessType === 'services'
                || (bool) ($modules['services'] ?? false)
                || (bool) ($configuration['products']['allow_service_items'] ?? false),
            'uses_service_orders' => (bool) ($modules['service_orders'] ?? false),
            'uses_production' => (bool) ($modules['production'] ?? false),
            'uses_offline_pos' => ($configuration['pos']['offline_mode'] ?? 'disabled') !== 'disabled',
        ];
    }
}
