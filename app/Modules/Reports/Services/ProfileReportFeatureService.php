<?php

namespace App\Modules\Reports\Services;

use App\Modules\SystemSuperadmin\Services\ActiveBusinessProfile;

class ProfileReportFeatureService
{
    public function features(): array
    {
        return [
            'sales' => ActiveBusinessProfile::enabled('quotes')
                || ActiveBusinessProfile::enabled('sales_notes')
                || ActiveBusinessProfile::enabled('pos'),
            'quotes' => ActiveBusinessProfile::enabled('quotes'),
            'purchases' => ActiveBusinessProfile::enabled('purchases'),
            'expenses' => ActiveBusinessProfile::enabled('expenses'),
            'inventory' => ActiveBusinessProfile::enabled('inventory'),
            'inventory_lots' => ActiveBusinessProfile::enabled('inventory')
                && (bool) (ActiveBusinessProfile::payload()['inventory']['lot_tracking_optional'] ?? false),
            'payments' => ActiveBusinessProfile::enabled('sales_notes'),
            'restaurant' => ActiveBusinessProfile::enabled('restaurant_tables')
                || ActiveBusinessProfile::capable('uses_tables')
                || ActiveBusinessProfile::capable('uses_kitchen_orders'),
            'service_orders' => ActiveBusinessProfile::enabled('service_orders')
                || ActiveBusinessProfile::capable('uses_service_orders'),
            'reservations' => ActiveBusinessProfile::enabled('reservations')
                || ActiveBusinessProfile::capable('uses_reservations'),
            'rentals' => ActiveBusinessProfile::enabled('rentals')
                || ActiveBusinessProfile::capable('uses_rentals'),
            'production' => ActiveBusinessProfile::enabled('production')
                || ActiveBusinessProfile::capable('uses_production'),
        ];
    }
}
