<?php

use App\Support\SystemCacheInvalidator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('billing_unit', 40)->default('service');
            $table->boolean('requires_materials')->default(false)->index();
            $table->boolean('requires_responsible')->default(false)->index();
            $table->boolean('requires_schedule')->default(false)->index();
            $table->boolean('is_delivery')->default(false)->index();
            $table->boolean('is_system')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->json('settings')->nullable();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        $this->seedSystemTypes();

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('service_type_id')->nullable()->after('item_type')->constrained('service_types')->nullOnDelete();
            $table->index(['item_type', 'service_type_id'], 'products_item_type_service_type_idx');
        });

        Schema::table('service_orders', function (Blueprint $table) {
            $table->foreignId('service_type_id')->nullable()->after('service_type')->constrained('service_types')->nullOnDelete();
        });

        $this->activateHardwareServiceSales();
        $this->activateHardwareServicePresets();
        SystemCacheInvalidator::bumpOperational();
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_type_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_item_type_service_type_idx');
            $table->dropConstrainedForeignId('service_type_id');
        });

        Schema::dropIfExists('service_types');
        SystemCacheInvalidator::bumpOperational();
    }

    private function seedSystemTypes(): void
    {
        $now = now();

        foreach ([
            [
                'code' => 'general_service',
                'name' => 'Servicio general',
                'description' => 'Servicio configurable para mano de obra, instalacion, mantenimiento o trabajo puntual.',
                'billing_unit' => 'service',
                'requires_materials' => false,
                'requires_responsible' => false,
                'requires_schedule' => false,
                'is_delivery' => false,
                'is_system' => true,
                'is_active' => true,
                'settings' => ['pricing_mode' => 'manual'],
                'sort_order' => 10,
            ],
            [
                'code' => 'transport_delivery',
                'name' => 'Transporte/Delivery',
                'description' => 'Servicio protegido para transporte, envio, reparto o entrega a domicilio.',
                'billing_unit' => 'route',
                'requires_materials' => false,
                'requires_responsible' => true,
                'requires_schedule' => false,
                'is_delivery' => true,
                'is_system' => true,
                'is_active' => true,
                'settings' => [
                    'pricing_mode' => 'route_distance',
                    'map_provider' => 'openstreetmap',
                    'routing_provider' => 'osrm',
                    'geocoding_provider' => 'nominatim',
                    'requires_backend_confirmation' => true,
                ],
                'sort_order' => 20,
            ],
        ] as $type) {
            DB::table('service_types')->updateOrInsert(
                ['code' => $type['code']],
                [
                    ...$type,
                    'settings' => json_encode($type['settings'], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ],
            );
        }
    }

    private function activateHardwareServiceSales(): void
    {
        if (! Schema::hasTable('business_profiles')) {
            return;
        }

        DB::table('business_profiles')
            ->where('business_type', 'hardware_store')
            ->where('status', 'active')
            ->orderBy('id')
            ->chunkById(50, function ($profiles) {
                foreach ($profiles as $profile) {
                    $configuration = json_decode($profile->configuration, true, 512, JSON_THROW_ON_ERROR);
                    data_set($configuration, 'modules.services', true);
                    data_set($configuration, 'capabilities.uses_services', true);
                    data_set($configuration, 'products.allow_service_items', true);
                    data_set($configuration, 'products.item_types', array_values(array_unique([
                        ...((array) data_get($configuration, 'products.item_types', ['physical'])),
                        'service',
                    ])));
                    data_set($configuration, 'services.mode', data_get($configuration, 'services.mode', 'mixed'));

                    DB::table('business_profiles')
                        ->where('id', $profile->id)
                        ->update([
                            'configuration' => json_encode($configuration, JSON_THROW_ON_ERROR),
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    private function activateHardwareServicePresets(): void
    {
        if (! Schema::hasTable('business_profile_presets')) {
            return;
        }

        DB::table('business_profile_presets')
            ->where('business_type', 'hardware_store')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(50, function ($presets) {
                foreach ($presets as $preset) {
                    $configuration = json_decode($preset->configuration, true, 512, JSON_THROW_ON_ERROR);
                    data_set($configuration, 'modules.services', true);
                    data_set($configuration, 'capabilities.uses_services', true);
                    data_set($configuration, 'products.allow_service_items', true);
                    data_set($configuration, 'products.item_types', array_values(array_unique([
                        ...((array) data_get($configuration, 'products.item_types', ['physical'])),
                        'service',
                    ])));
                    data_set($configuration, 'services.mode', data_get($configuration, 'services.mode', 'mixed'));

                    DB::table('business_profile_presets')
                        ->where('id', $preset->id)
                        ->update([
                            'configuration' => json_encode($configuration, JSON_THROW_ON_ERROR),
                            'updated_at' => now(),
                        ]);
                }
            });
    }
};
