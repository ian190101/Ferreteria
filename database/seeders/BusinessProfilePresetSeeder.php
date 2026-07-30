<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\SystemSuperadmin\Models\BusinessProfilePreset;
use App\Modules\SystemSuperadmin\Services\BusinessProfilePresetFactory;
use App\Support\SystemRoles;
use Illuminate\Database\Seeder;

class BusinessProfilePresetSeeder extends Seeder
{
    public function run(): void
    {
        $userId = User::role(SystemRoles::SYSTEM_SUPERADMIN)->value('id')
            ?? User::query()->value('id');

        foreach (app(BusinessProfilePresetFactory::class)->officialPresets() as $preset) {
            BusinessProfilePreset::withTrashed()->updateOrCreate(
                ['name' => $preset['name']],
                [
                    'business_type' => $preset['business_type'],
                    'description' => $preset['description'],
                    'is_system' => true,
                    'configuration' => $preset['configuration'],
                    'created_by' => $userId,
                    'updated_by' => $userId,
                    'deleted_at' => null,
                ],
            );
        }
    }
}
