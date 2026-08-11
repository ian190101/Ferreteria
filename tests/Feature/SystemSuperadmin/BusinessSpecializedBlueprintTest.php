<?php

use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Models\CalculationFormula;
use App\Modules\SystemSuperadmin\Models\CustomFieldDefinition;
use App\Modules\SystemSuperadmin\Models\DynamicEntity;
use App\Modules\SystemSuperadmin\Models\DynamicFormDefinition;
use App\Modules\SystemSuperadmin\Models\DynamicRelationshipDefinition;
use App\Modules\SystemSuperadmin\Models\WorkflowDefinition;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use App\Modules\SystemSuperadmin\Services\BusinessProfilePresetFactory;
use App\Modules\SystemSuperadmin\Services\BusinessSpecializedBlueprintFactory;
use App\Modules\SystemSuperadmin\Services\BusinessSpecializedBlueprintInstaller;
use App\Modules\SystemSuperadmin\Services\CalculationFormulaService;
use App\Modules\SystemSuperadmin\Services\DynamicFormService;
use Illuminate\Support\Facades\Cache;

it('define blueprints especializados para presets que requieren logica dinamica', function () {
    $factory = app(BusinessSpecializedBlueprintFactory::class);

    expect($factory->presetNames())->toContain(
        'Odontologia',
        'Consultorio medico',
        'Prestamos y casa de empeno',
        'Arquitectura y construccion',
        'Mecanica autos y motos',
        'Reparacion celulares y laptops',
        'Transporte y logistica',
    )
        ->and($factory->forPreset('Odontologia')['entities'])->not->toBeEmpty()
        ->and($factory->forPreset('Prestamos y casa de empeno')['formulas'])->not->toBeEmpty()
        ->and($factory->forPreset('Arquitectura y construccion')['formulas'])->not->toBeEmpty();
});

it('instala blueprints especializados de forma idempotente sin cambiar el perfil activo', function () {
    $configuration = BusinessProfileConfiguration::normalized([
        'identity' => ['business_type' => 'hardware_store'],
        'sales' => ['workflow' => 'quotation_to_sale_note'],
    ]);

    $profile = BusinessProfile::query()->create([
        'name' => 'Ferreteria activa blueprint',
        'business_type' => 'hardware_store',
        'status' => 'active',
        'configuration' => $configuration,
        'applied_at' => now(),
    ]);

    $installer = app(BusinessSpecializedBlueprintInstaller::class);
    $first = $installer->installForPreset('Odontologia');
    $second = $installer->installForPreset('Odontologia');

    expect($first['entities'])->toBeGreaterThan(0)
        ->and($second['entities'])->toBe($first['entities'])
        ->and(DynamicEntity::query()->where('code', 'paciente')->count())->toBe(1)
        ->and(DynamicEntity::query()->where('code', 'odontograma')->count())->toBe(1)
        ->and(CustomFieldDefinition::query()->where('entity_type', 'odontograma')->where('code', 'pieza_dental')->count())->toBe(1)
        ->and(DynamicRelationshipDefinition::query()->where('code', 'paciente_odontogramas')->count())->toBe(1)
        ->and(DynamicFormDefinition::query()->where('code', 'odontograma_atencion')->count())->toBe(1)
        ->and(WorkflowDefinition::query()->where('code', 'flujo_tratamiento_dental')->count())->toBe(1)
        ->and($profile->refresh()->configuration)->toBe($configuration)
        ->and($profile->status)->toBe('active');
});

it('resuelve formularios especializados solo cuando el perfil activa los motores requeridos', function () {
    app(BusinessSpecializedBlueprintInstaller::class)->installForPreset('Odontologia');

    $service = app(DynamicFormService::class);

    expect($service->resolve('odontograma', 'odontograma_atencion'))->toBeNull();

    $preset = collect(app(BusinessProfilePresetFactory::class)->officialPresets())
        ->firstWhere('name', 'Odontologia');

    BusinessProfile::query()->create([
        'name' => 'Odontologia activa',
        'business_type' => 'dental_clinic',
        'status' => 'active',
        'configuration' => $preset['configuration'],
        'applied_at' => now(),
    ]);
    Cache::flush();

    $form = $service->resolve('odontograma', 'odontograma_atencion');

    expect($form)->not->toBeNull()
        ->and($form['name'])->toBe('Registro de odontograma')
        ->and(collect($form['fields'])->pluck('code')->all())->toBe(['pieza_dental', 'tipo_denticion', 'estado_pieza']);
});

it('materializa formulas especializadas y las evalua detras del perfil correcto', function () {
    app(BusinessSpecializedBlueprintInstaller::class)->installForPreset('Arquitectura y construccion');

    expect(CalculationFormula::query()->where('code', 'volumen_losa_m3')->exists())->toBeTrue();

    $service = app(CalculationFormulaService::class);

    expect(fn () => $service->evaluate('volumen_losa_m3', ['area_m2' => 100, 'espesor_cm' => 12], 'computo_metrico'))
        ->toThrow(\Illuminate\Validation\ValidationException::class);

    $preset = collect(app(BusinessProfilePresetFactory::class)->officialPresets())
        ->firstWhere('name', 'Arquitectura y construccion');

    BusinessProfile::query()->create([
        'name' => 'Construccion activa',
        'business_type' => 'construction',
        'status' => 'active',
        'configuration' => $preset['configuration'],
        'applied_at' => now(),
    ]);
    Cache::flush();

    expect($service->evaluate('volumen_losa_m3', ['area_m2' => 100, 'espesor_cm' => 12], 'computo_metrico'))->toBe(12.0);
});
