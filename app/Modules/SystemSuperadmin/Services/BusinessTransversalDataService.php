<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\Product;
use App\Modules\SystemSuperadmin\Models\BusinessCurrency;
use App\Modules\SystemSuperadmin\Models\BusinessModuleLicense;
use App\Modules\SystemSuperadmin\Models\BusinessStateDefinition;
use App\Modules\SystemSuperadmin\Models\CommissionRule;
use App\Modules\SystemSuperadmin\Models\CustomFieldDefinition;
use App\Modules\SystemSuperadmin\Models\ImportProfileTemplate;
use App\Modules\SystemSuperadmin\Models\NotificationRule;
use App\Modules\SystemSuperadmin\Models\PriceList;
use App\Modules\SystemSuperadmin\Models\PrinterProfile;
use App\Modules\SystemSuperadmin\Models\ReservableResource;

class BusinessTransversalDataService
{
    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'summary' => [
                'custom_fields' => CustomFieldDefinition::query()->count(),
                'states' => BusinessStateDefinition::query()->count(),
                'resources' => ReservableResource::query()->count(),
                'price_lists' => PriceList::query()->count(),
                'commissions' => CommissionRule::query()->count(),
                'notifications' => NotificationRule::query()->count(),
                'currencies' => BusinessCurrency::query()->count(),
                'printers' => PrinterProfile::query()->count(),
                'licenses' => BusinessModuleLicense::query()->count(),
                'imports' => ImportProfileTemplate::query()->count(),
            ],
            'customFields' => CustomFieldDefinition::query()->orderBy('entity_type')->orderBy('sort_order')->orderBy('label')->limit(80)->get(),
            'states' => BusinessStateDefinition::query()->orderBy('entity_type')->orderBy('sort_order')->orderBy('label')->limit(80)->get(),
            'resources' => ReservableResource::query()->with('branch:id,name')->latest('updated_at')->limit(80)->get(),
            'priceLists' => PriceList::query()->with('branch:id,name')->latest('updated_at')->limit(80)->get(),
            'commissions' => CommissionRule::query()->with(['branch:id,name', 'product:id,name'])->latest('updated_at')->limit(80)->get(),
            'notifications' => NotificationRule::query()->latest('updated_at')->limit(80)->get(),
            'currencies' => BusinessCurrency::query()->orderByDesc('is_base')->orderBy('code')->limit(80)->get(),
            'printers' => PrinterProfile::query()->with('branch:id,name')->latest('updated_at')->limit(80)->get(),
            'licenses' => BusinessModuleLicense::query()->orderBy('module')->limit(80)->get(),
            'imports' => ImportProfileTemplate::query()->orderBy('entity_type')->orderBy('name')->limit(80)->get(),
            'branches' => Branch::query()->select('id', 'name')->where('is_active', true)->orderBy('name')->get(),
            'products' => Product::query()->select('id', 'name')->where('is_active', true)->orderBy('name')->limit(120)->get(),
            'options' => BusinessTransversalConfiguration::options(),
        ];
    }
}
