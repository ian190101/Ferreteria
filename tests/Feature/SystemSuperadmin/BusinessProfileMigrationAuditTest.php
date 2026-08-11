<?php

use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use App\Modules\SystemSuperadmin\Services\BusinessProfileMigrationAuditService;
use Illuminate\Support\Facades\Cache;

it('genera un preview de migracion 2 sin escribir ni modificar el perfil activo de ferreteria', function () {
    Cache::flush();

    $legacyConfiguration = BusinessProfileConfiguration::normalized([
        'identity' => ['business_type' => 'hardware_store'],
        'modules' => [
            'quotes' => true,
            'sales_notes' => true,
            'purchases' => true,
            'cash' => true,
            'inventory' => true,
            'services' => false,
        ],
        'sales' => [
            'workflow' => 'quotation_to_sale_note',
            'quotation_mode' => 'required',
            'customer_mode' => 'required',
        ],
    ]);

    $profile = BusinessProfile::query()->create([
        'name' => 'Ferreteria actual',
        'business_type' => 'hardware_store',
        'status' => 'active',
        'configuration' => $legacyConfiguration,
        'applied_at' => now(),
    ]);
    $profilesBeforePreview = BusinessProfile::query()->count();

    $preview = app(BusinessProfileMigrationAuditService::class)
        ->previewToVersionTwo('hardware_store', $legacyConfiguration);

    expect($preview['safe_to_apply'])->toBeTrue()
        ->and($preview['configuration']['schema_version'])->toBe(2)
        ->and($preview['configuration']['identity']['business_type'])->toBe('hardware_store')
        ->and($preview['configuration']['modules']['quotes'])->toBeTrue()
        ->and($preview['configuration']['modules']['sales_notes'])->toBeTrue()
        ->and($preview['configuration']['sales']['workflow'])->toBe('quotation_to_sale_note')
        ->and($preview['configuration']['feature_flags']['dynamic_entities_engine'])->toBeFalse()
        ->and($preview['configuration']['feature_flags']['dynamic_fields_engine'])->toBeFalse()
        ->and($preview['configuration']['migration']['allow_destructive_migrations'])->toBeFalse()
        ->and($preview['requires_regression_tests'])->toBeTrue()
        ->and(collect($preview['anti_rupture'])->where('status', 'blocked'))->toBeEmpty()
        ->and(BusinessProfile::query()->count())->toBe($profilesBeforePreview)
        ->and($profile->refresh()->configuration)->toBe($legacyConfiguration);
});

it('documenta el mapeo entre configuracion legacy y capacidades 2.0', function () {
    $preview = app(BusinessProfileMigrationAuditService::class)
        ->previewToVersionTwo('mixed', [
            'modules' => [
                'inventory' => true,
                'pos' => true,
                'cash' => true,
                'cash_flow' => true,
                'banks' => true,
                'billing' => true,
                'deliveries' => true,
                'services' => true,
                'service_orders' => true,
                'production' => true,
            ],
            'billing' => ['enabled' => true],
            'cash' => ['required_to_sell' => true],
            'sales' => ['workflow' => 'direct_sale'],
        ]);

    expect($preview['mapping']['modules_to_capabilities']['modules.inventory'])->toBe('capabilities.uses_inventory')
        ->and($preview['configuration']['capabilities']['uses_inventory'])->toBeTrue()
        ->and($preview['configuration']['capabilities']['uses_pos'])->toBeTrue()
        ->and($preview['configuration']['capabilities']['uses_cash'])->toBeTrue()
        ->and($preview['configuration']['capabilities']['uses_cash_flow'])->toBeTrue()
        ->and($preview['configuration']['capabilities']['uses_banks'])->toBeTrue()
        ->and($preview['configuration']['capabilities']['uses_billing'])->toBeTrue()
        ->and($preview['configuration']['capabilities']['uses_delivery'])->toBeTrue()
        ->and($preview['configuration']['capabilities']['uses_services'])->toBeTrue()
        ->and($preview['configuration']['capabilities']['uses_service_orders'])->toBeTrue()
        ->and($preview['configuration']['capabilities']['uses_production'])->toBeTrue();
});

it('bloquea el preview si la migracion intenta habilitar cambios destructivos o motores sin permiso', function () {
    $preview = app(BusinessProfileMigrationAuditService::class)
        ->previewToVersionTwo('hardware_store', [
            'feature_flags' => [
                'dynamic_entities_engine' => true,
            ],
            'migration' => [
                'allow_destructive_migrations' => true,
                'preserve_legacy_behavior' => false,
                'requires_regression_tests' => false,
            ],
        ]);

    expect($preview['safe_to_apply'])->toBeFalse()
        ->and(implode(' ', $preview['blockers']))->toContain('migraciones destructivas')
        ->and(implode(' ', $preview['blockers']))->toContain('comportamiento legado')
        ->and(implode(' ', $preview['blockers']))->toContain('motores dinamicos activos')
        ->and(implode(' ', $preview['warnings']))->toContain('pruebas de regresion');
});
