<?php

use App\Modules\SystemSuperadmin\Services\BusinessCapabilityCatalog;
use App\Modules\SystemSuperadmin\Services\BusinessProfileCompatibilityValidator;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use App\Modules\SystemSuperadmin\Services\BusinessProfileDiffService;
use App\Modules\SystemSuperadmin\Services\BusinessProfileMigrationMapper;

it('define un catalogo formal de capacidades reutilizables', function () {
    expect(BusinessCapabilityCatalog::keys())
        ->toContain('uses_inventory')
        ->toContain('uses_pos')
        ->toContain('uses_tables')
        ->toContain('uses_service_orders')
        ->toContain('uses_printer_profiles');

    expect(BusinessCapabilityCatalog::recommendedFor('restaurant'))
        ->toContain('uses_tables')
        ->toContain('uses_recipes');
});

it('detecta combinaciones incompatibles antes de aplicar un perfil', function () {
    $configuration = BusinessProfileConfiguration::defaults();
    $configuration['capabilities']['uses_tables'] = true;
    $configuration['capabilities']['uses_pos'] = false;

    $result = (new BusinessProfileCompatibilityValidator)->validate('restaurant', $configuration);

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->toContain('Las mesas requieren POS restaurante activo.');
});

it('migra configuracion previa al esquema 2 sin cambiar el flujo de ferreteria actual', function () {
    $configuration = BusinessProfileConfiguration::defaults();
    $configuration['modules']['quotes'] = true;
    $configuration['modules']['pos'] = false;
    $configuration['sales']['workflow'] = 'quotation_to_sale_note';

    $mapped = (new BusinessProfileMigrationMapper)->toVersionTwo('hardware_store', $configuration);

    expect($mapped['schema_version'])->toBe(2)
        ->and($mapped['identity']['business_type'])->toBe('hardware_store')
        ->and($mapped['sales']['workflow'])->toBe('quotation_to_sale_note')
        ->and($mapped['capabilities']['allows_quote_to_sale'])->toBeTrue()
        ->and($mapped['capabilities']['uses_pos'])->toBeFalse();
});

it('compara configuraciones por secciones para versionado visual', function () {
    $before = BusinessProfileConfiguration::defaults();
    $after = BusinessProfileConfiguration::defaults();
    $after['sales']['workflow'] = 'pos';
    $after['modules']['pos'] = true;

    $diff = (new BusinessProfileDiffService)->compare($before, $after);

    expect($diff)->toHaveKeys(['modules', 'sales'])
        ->and(collect($diff['sales'])->pluck('key')->all())->toContain('sales.workflow')
        ->and(collect($diff['modules'])->pluck('key')->all())->toContain('modules.pos');
});

it('normaliza politicas 2 para datos documentos anulaciones y modo degradado', function () {
    $configuration = BusinessProfileConfiguration::normalized([]);

    expect($configuration['policies']['data']['export_requires_permission'])->toBeTrue()
        ->and($configuration['documents']['numbering']['by_branch'])->toBeTrue()
        ->and($configuration['policies']['voids_returns']['requires_reason'])->toBeTrue()
        ->and($configuration['policies']['degraded_mode']['inactive_modules_read_only_history'])->toBeTrue();
});
