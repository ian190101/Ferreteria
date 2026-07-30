<?php

namespace Database\Seeders;

use App\Modules\SystemSuperadmin\Models\BusinessCapability;
use App\Modules\SystemSuperadmin\Services\BusinessCapabilityCatalog;
use Illuminate\Database\Seeder;

class BusinessCapabilitySeeder extends Seeder
{
    public function run(): void
    {
        foreach (BusinessCapabilityCatalog::all() as $capability) {
            BusinessCapability::query()->updateOrCreate(
                ['code' => $capability['code']],
                [
                    'group' => $capability['group'],
                    'name' => $capability['name'],
                    'description' => $capability['description'],
                    'recommended_business_types' => $capability['recommended_business_types'],
                    'dependencies' => $capability['dependencies'],
                    'conflicts' => $capability['conflicts'],
                    'metadata' => $capability['metadata'],
                    'is_active' => $capability['is_active'],
                ],
            );
        }
    }
}
