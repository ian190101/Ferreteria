<?php

use App\Modules\SystemSuperadmin\Services\BusinessPresetReadinessService;
use App\Modules\SystemSuperadmin\Services\BusinessProfilePresetFactory;

it('evalua readiness de todos los presets oficiales sin bloqueos', function () {
    $readiness = app(BusinessPresetReadinessService::class)->all();

    expect($readiness)->toHaveCount(32)
        ->and(collect($readiness)->pluck('name')->all())->toContain(
            'Ferreteria con cotizacion y nota',
            'Odontologia',
            'Prestamos y casa de empeno',
            'Transporte y logistica',
        )
        ->and(collect($readiness)->where('ready', false)->values()->all())->toBe([]);
});

it('exige blueprint especializado cuando un preset activa capacidades de rubro dinamico', function () {
    $preset = collect(app(BusinessProfilePresetFactory::class)->officialPresets())
        ->firstWhere('name', 'Odontologia');

    $preset['name'] = 'Odontologia sin blueprint';

    $result = app(BusinessPresetReadinessService::class)->evaluate($preset);

    expect($result['ready'])->toBeFalse()
        ->and(collect($result['blockers'])->pluck('code')->all())->toContain('specialized_blueprint');
});

it('bloquea presets con capacidades que no tienen su motor tecnico activo', function () {
    $preset = collect(app(BusinessProfilePresetFactory::class)->officialPresets())
        ->firstWhere('name', 'Prestamos y casa de empeno');

    data_set($preset, 'configuration.feature_flags.formula_engine', false);

    $result = app(BusinessPresetReadinessService::class)->evaluate($preset);

    expect($result['ready'])->toBeFalse()
        ->and(collect($result['blockers'])->pluck('code')->all())->toContain('compatibility')
        ->and(collect($result['blockers'])->pluck('code')->all())->toContain('engine_uses_formula_calculations');
});

it('reporta plantillas especializadas y blueprints sin tocar el perfil activo', function () {
    $readiness = collect(app(BusinessPresetReadinessService::class)->all())->keyBy('name');

    expect($readiness['Ferreteria con cotizacion y nota']['specialized_reports'])->toBeGreaterThan(0)
        ->and($readiness['Ferreteria con cotizacion y nota']['specialized_blueprint'])->toBeFalse()
        ->and($readiness['Odontologia']['specialized_blueprint'])->toBeTrue()
        ->and($readiness['Odontologia']['specialized_reports'])->toBeGreaterThan(0)
        ->and($readiness['Prestamos y casa de empeno']['specialized_blueprint'])->toBeTrue()
        ->and($readiness['Prestamos y casa de empeno']['specialized_reports'])->toBeGreaterThan(0);
});

