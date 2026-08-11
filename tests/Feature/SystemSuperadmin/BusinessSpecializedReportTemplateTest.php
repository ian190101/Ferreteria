<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Exports\Services\ExportDatasetService;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Models\DynamicReportTemplate;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use App\Modules\SystemSuperadmin\Services\BusinessSpecializedReportTemplateFactory;
use App\Modules\SystemSuperadmin\Services\BusinessSpecializedReportTemplateInstaller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('define plantillas de reportes especializadas por rubro', function () {
    $factory = app(BusinessSpecializedReportTemplateFactory::class);

    expect(array_keys($factory->all()))->toContain(
        'Restaurante completo',
        'Odontologia',
        'Prestamos y casa de empeno',
        'Arquitectura y construccion',
        'Transporte y logistica',
        'Fabrica simple',
    )
        ->and($factory->forPreset('Restaurante completo'))->toHaveCount(2)
        ->and(collect($factory->forPreset('Odontologia'))->pluck('code')->all())->toContain('reporte_odontologia_tratamientos')
        ->and(collect($factory->forPreset('Prestamos y casa de empeno'))->pluck('metadata')->pluck('sensitive')->filter()->count())->toBeGreaterThan(0);
});

it('instala plantillas de reportes por preset sin duplicar ni tocar perfiles activos', function () {
    $configuration = BusinessProfileConfiguration::normalized([
        'identity' => ['business_type' => 'hardware_store'],
        'sales' => ['workflow' => 'quotation_to_sale_note'],
    ]);

    $profile = BusinessProfile::query()->create([
        'name' => 'Ferreteria activa reportes rubro',
        'business_type' => 'hardware_store',
        'status' => 'active',
        'configuration' => $configuration,
        'applied_at' => now(),
    ]);

    $installer = app(BusinessSpecializedReportTemplateInstaller::class);
    $first = $installer->installForPreset('Odontologia');
    $second = $installer->installForPreset('Odontologia');

    expect($first['templates'])->toBe(2)
        ->and($second['templates'])->toBe(2)
        ->and(DynamicReportTemplate::query()->where('code', 'reporte_odontologia_tratamientos')->count())->toBe(1)
        ->and(DynamicReportTemplate::query()->where('code', 'reporte_odontologia_citas')->count())->toBe(1)
        ->and($profile->refresh()->configuration)->toBe($configuration)
        ->and($profile->status)->toBe('active');
});

it('expone reportes por rubro solo con motor activo y permisos de exportacion', function () {
    app(BusinessSpecializedReportTemplateInstaller::class)->installForPreset('Restaurante completo');

    $branch = Branch::query()->create([
        'name' => 'Sucursal reportes restaurante',
        'code' => 'REP-REST',
        'barcode' => 'BR-REP-REST',
        'is_active' => true,
    ]);

    foreach (['restaurant.view', 'reports.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $role = Role::findOrCreate('reportes restaurante', 'web');
    $role->syncPermissions(['restaurant.view', 'reports.view']);

    $user = User::factory()->create(['branch_id' => $branch->id]);
    $user->assignRole($role);

    $request = Request::create('/exports');
    $request->setUserResolver(fn () => $user);

    expect(app(ExportDatasetService::class)->catalog($request))->not->toHaveKey('report_template__reporte_restaurante_mesas');

    BusinessProfile::query()->create([
        'name' => 'Restaurante reportes activos',
        'business_type' => 'restaurant',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'modules' => [
                'exports' => true,
                'reports' => true,
                'restaurant_tables' => true,
                'kitchen_orders' => true,
            ],
            'capabilities' => [
                'uses_tables' => true,
                'uses_pos' => true,
                'uses_kitchen_orders' => true,
                'uses_report_templates' => true,
            ],
            'feature_flags' => [
                'report_templates_engine' => true,
            ],
        ]),
        'applied_at' => now(),
    ]);
    Cache::flush();

    $catalog = app(ExportDatasetService::class)->catalog($request);

    expect($catalog)->toHaveKey('report_template__reporte_restaurante_mesas')
        ->and($catalog['report_template__reporte_restaurante_mesas']['label'])->toBe('Ventas por mesa y mesero')
        ->and($catalog['report_template__reporte_restaurante_mesas']['base_module'])->toBe('restaurant_summary');
});

it('bloquea reportes sensibles por rubro sin permiso de exportacion sensible', function () {
    app(BusinessSpecializedReportTemplateInstaller::class)->installForPreset('Odontologia');

    $branch = Branch::query()->create([
        'name' => 'Sucursal reportes sensibles',
        'code' => 'REP-SENS',
        'barcode' => 'BR-REP-SENS',
        'is_active' => true,
    ]);

    foreach (['service-orders.view', 'reports.view', 'exports.sensitive'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $basicRole = Role::findOrCreate('reportes salud basico', 'web');
    $basicRole->syncPermissions(['service-orders.view', 'reports.view']);
    $sensitiveRole = Role::findOrCreate('reportes salud sensible', 'web');
    $sensitiveRole->syncPermissions(['service-orders.view', 'reports.view', 'exports.sensitive']);

    $basicUser = User::factory()->create(['branch_id' => $branch->id]);
    $allowedUser = User::factory()->create(['branch_id' => $branch->id]);
    $basicUser->assignRole($basicRole);
    $allowedUser->assignRole($sensitiveRole);

    BusinessProfile::query()->create([
        'name' => 'Odontologia reportes sensibles',
        'business_type' => 'dental_clinic',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'modules' => [
                'exports' => true,
                'reports' => true,
                'services' => true,
                'service_orders' => true,
            ],
            'capabilities' => [
                'uses_services' => true,
                'uses_service_orders' => true,
                'uses_report_templates' => true,
            ],
            'feature_flags' => [
                'report_templates_engine' => true,
            ],
        ]),
        'applied_at' => now(),
    ]);
    Cache::flush();

    $basicRequest = Request::create('/exports');
    $basicRequest->setUserResolver(fn () => $basicUser);
    $allowedRequest = Request::create('/exports');
    $allowedRequest->setUserResolver(fn () => $allowedUser);

    expect(app(ExportDatasetService::class)->catalog($basicRequest))->not->toHaveKey('report_template__reporte_odontologia_tratamientos')
        ->and(app(ExportDatasetService::class)->catalog($allowedRequest))->toHaveKey('report_template__reporte_odontologia_tratamientos');
});
