<?php

use App\Models\User;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Models\BusinessProfilePreset;
use App\Modules\SystemSuperadmin\Services\BusinessProfileCompatibilityValidator;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use App\Modules\SystemSuperadmin\Services\BusinessProfilePresetFactory;
use Database\Seeders\BusinessProfilePresetSeeder;

it('define los presets oficiales acordados para tipos de negocio principales', function () {
    $presets = app(BusinessProfilePresetFactory::class)->officialPresets();
    $names = collect($presets)->pluck('name')->all();

    expect($presets)->toHaveCount(11)
        ->and($names)->toContain(
            'Ferreteria con cotizacion y nota',
            'Ferreteria POS',
            'Restaurante completo',
            'Comida rapida y cafeteria',
            'Servicios profesionales',
            'Taller y servicio tecnico',
            'Supermercado',
            'Libreria y papeleria',
            'Tienda general y ropa',
            'Fabrica simple',
            'Alquileres',
        );
});

it('mantiene todos los presets oficiales normalizados y compatibles', function () {
    $validator = app(BusinessProfileCompatibilityValidator::class);

    foreach (app(BusinessProfilePresetFactory::class)->officialPresets() as $preset) {
        $configuration = BusinessProfileConfiguration::normalized($preset['configuration']);
        $result = $validator->validate($preset['business_type'], $configuration);

        expect($configuration['schema_version'])->toBe(2)
            ->and($configuration['identity']['business_type'])->toBe($preset['business_type'])
            ->and($result['errors'])->toBe([], "Preset {$preset['name']} tiene errores: ".implode(' | ', $result['errors']));
    }
});

it('expresa reglas clave por preset sin modificar el perfil activo', function () {
    $presets = collect(app(BusinessProfilePresetFactory::class)->officialPresets())->keyBy('name');

    expect(data_get($presets['Ferreteria con cotizacion y nota'], 'configuration.sales.workflow'))->toBe('quotation_to_sale_note')
        ->and(data_get($presets['Restaurante completo'], 'configuration.restaurant.tables_enabled'))->toBeTrue()
        ->and(data_get($presets['Restaurante completo'], 'configuration.capabilities.uses_kitchen_orders'))->toBeTrue()
        ->and(data_get($presets['Comida rapida y cafeteria'], 'configuration.restaurant.tables_enabled'))->toBeFalse()
        ->and(data_get($presets['Taller y servicio tecnico'], 'configuration.services.technician_required'))->toBeTrue()
        ->and(data_get($presets['Supermercado'], 'configuration.pos.scanner_mode'))->toBe('required')
        ->and(data_get($presets['Tienda general y ropa'], 'configuration.products.variants_enabled'))->toBeTrue()
        ->and(data_get($presets['Fabrica simple'], 'configuration.production_flow.bom_enabled'))->toBeTrue()
        ->and(data_get($presets['Alquileres'], 'configuration.rentals.deposit_required'))->toBeTrue();
});

it('siembra presets oficiales sin tocar el perfil activo de ferreteria', function () {
    $user = User::factory()->create();
    $activeConfiguration = BusinessProfileConfiguration::normalized([
        'identity' => ['business_type' => 'hardware_store'],
        'sales' => ['workflow' => 'quotation_to_sale_note'],
    ]);

    $profile = BusinessProfile::query()->create([
        'name' => 'Ferreteria cliente en pruebas',
        'business_type' => 'hardware_store',
        'status' => 'active',
        'configuration' => $activeConfiguration,
        'applied_at' => now(),
        'applied_by' => $user->id,
    ]);

    $this->seed(BusinessProfilePresetSeeder::class);

    expect(BusinessProfilePreset::query()->where('is_system', true)->count())->toBe(11)
        ->and($profile->refresh()->configuration)->toBe($activeConfiguration)
        ->and($profile->status)->toBe('active');
});
