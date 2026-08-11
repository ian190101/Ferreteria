<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Settings\Models\MaintenanceBackup;
use App\Modules\Settings\Models\ApplicationLicense;
use App\Modules\Settings\Models\ImportBatch;
use App\Modules\Settings\Models\SystemSetting;
use App\Support\SystemRoles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function settingsUser(array $permissions): User
{
    $suffix = strtoupper(substr(uniqid(), -6));

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'sistema-test-'.$suffix, 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $branch = Branch::query()->create([
        'name' => 'Sistema central',
        'code' => 'SYS-'.$suffix,
        'barcode' => 'BR-SYS-'.$suffix,
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);

    $user->assignRole($role);
    $user->accessibleBranches()->syncWithoutDetaching([$branch->id]);

    return $user;
}

it('muestra la seccion de respaldos del sistema', function () {
    $user = settingsUser(['settings.manage']);

    $this->actingAs($user)
        ->get(route('settings.system.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/System/Index', false)
            ->has('backups.data', 0)
        );
});

it('genera backup operativo en disco local', function () {
    Storage::fake('local');

    $user = settingsUser(['settings.manage']);
    Product::query()->create([
        'name' => 'Producto backup',
        'sku' => 'BACK-001',
        'barcode' => 'PR-BACK-001',
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('settings.system.backups.store'), ['format' => 'json'])
        ->assertRedirect(route('settings.system.index'));

    $backup = MaintenanceBackup::query()->firstOrFail();

    Storage::disk('local')->assertExists($backup->path);
    expect($backup->metadata['format'])->toBe('json');
});

it('bloquea sistema sin permiso', function () {
    $blocked = settingsUser([]);

    $this->actingAs($blocked)
        ->get(route('settings.system.index'))
        ->assertForbidden();
});

it('actualiza configuracion productiva y la expone en sistema', function () {
    $user = settingsUser(['settings.manage']);

    $this->actingAs($user)
        ->put(route('settings.system.production.update'), [
            'backup_frequency' => 'daily',
            'backup_format' => 'sql',
            'backup_retention_days' => 21,
            'siat_auto_cufd' => true,
            'siat_auto_catalog_sync' => true,
            'technical_alert_email' => 'soporte@example.com',
            'offline_pos_enabled' => false,
            'license_validation_mode' => 'hybrid',
        ])
        ->assertRedirect(route('settings.system.index'));

    $setting = SystemSetting::query()->where('key', 'production.maintenance')->firstOrFail();

    expect($setting->value['backup_retention_days'])->toBe(21)
        ->and($setting->value['technical_alert_email'])->toBe('soporte@example.com')
        ->and($setting->value['license_validation_mode'])->toBe('hybrid');
});

it('importa productos desde csv con stock inicial por sucursal', function () {
    $user = settingsUser(['settings.manage']);
    $branchId = $user->branch_id;
    $file = UploadedFile::fake()->createWithContent('productos.csv', "nombre,sku,barcode,unidad,categoria,stock,precio_compra,precio_venta\nCasco industrial,CASCO-IMP,775100,unidad,Seguridad,15,20,35\n");

    $this->actingAs($user)
        ->post(route('settings.system.imports.store'), [
            'module' => 'products',
            'branch_id' => $branchId,
            'file' => $file,
        ])
        ->assertRedirect(route('settings.system.index'));

    $product = Product::query()->where('sku', 'CASCO-IMP')->firstOrFail();

    expect(ImportBatch::query()->where('module', 'products')->where('status', 'completed')->exists())->toBeTrue()
        ->and(ProductBranchStock::query()->where('product_id', $product->id)->where('branch_id', $branchId)->value('available_meters'))->toBe('15.000');
});

it('importa productos desde excel xlsx con stock inicial', function () {
    if (! class_exists(ZipArchive::class)) {
        $this->markTestSkipped('La extension PHP ZipArchive es necesaria para generar el XLSX de prueba.');
    }

    $user = settingsUser(['settings.manage']);
    $branchId = $user->branch_id;
    $file = fakeXlsxUpload([
        ['nombre', 'sku', 'barcode', 'unidad', 'categoria', 'stock', 'precio_compra', 'precio_venta'],
        ['Guante reforzado', 'GUA-XLSX', '775200', 'par', 'Seguridad', '8', '12', '25'],
    ]);

    $this->actingAs($user)
        ->post(route('settings.system.imports.store'), [
            'module' => 'products',
            'branch_id' => $branchId,
            'file' => $file,
        ])
        ->assertRedirect(route('settings.system.index'));

    $product = Product::query()->where('sku', 'GUA-XLSX')->firstOrFail();

    expect(ProductBranchStock::query()->where('product_id', $product->id)->where('branch_id', $branchId)->value('available_meters'))->toBe('8.000');
});

it('actualiza permisos finos y envia alerta de prueba', function () {
    $user = settingsUser(['settings.manage', 'production.alerts.manage']);

    $this->actingAs($user)
        ->put(route('settings.system.production.update'), [
            'backup_frequency' => 'daily',
            'backup_format' => 'sql',
            'backup_retention_days' => 14,
            'siat_auto_cufd' => true,
            'siat_auto_catalog_sync' => true,
            'technical_alert_email' => 'soporte@example.com',
            'offline_pos_enabled' => true,
            'license_validation_mode' => 'offline',
        ])
        ->assertRedirect(route('settings.system.index'));

    $this->actingAs($user)
        ->put(route('settings.system.fine-permissions.update'), [
            'cost_visibility_requires_permission' => true,
            'void_sales_requires_permission' => true,
            'void_payments_requires_permission' => true,
            'sensitive_exports_requires_permission' => true,
            'max_discount_percent' => 12.5,
        ])
        ->assertRedirect(route('settings.system.index'));

    $this->actingAs($user)
        ->post(route('settings.system.alerts.test'))
        ->assertRedirect(route('settings.system.index'));

    expect(SystemSetting::query()->where('key', 'security.fine_permissions')->value('value')['max_discount_percent'])->toBe(12.5);
    expect(\App\Modules\Settings\Models\MaintenanceLog::query()
        ->whereIn('event', ['production_alert_sent', 'production_alert_failed'])
        ->exists())->toBeTrue();
});

it('sistemasuperadmin ejecuta asistente inicial visual', function () {
    $user = settingsUser(['settings.manage', 'setup.wizard.manage']);
    $role = Role::firstOrCreate(['name' => SystemRoles::SYSTEM_SUPERADMIN, 'guard_name' => 'web']);
    $role->syncPermissions(Permission::query()->where('guard_name', 'web')->pluck('name')->all());
    $user->syncRoles([$role->name]);

    $this->actingAs($user)
        ->post(route('settings.system.setup-wizard.run'), [
            'business_name' => 'Ferreteria Produccion',
            'branch_name' => 'Sucursal Produccion',
            'branch_code' => 'PROD',
            'branch_address' => 'Av. Principal',
            'branch_phone' => '70000000',
            'primary_color' => '#2563eb',
            'secondary_color' => '#111827',
            'admin_name' => 'Admin Cliente',
            'admin_email' => 'cliente-admin@example.com',
            'admin_password' => 'clave-temporal-123',
        ])
        ->assertRedirect(route('settings.system.index'));

    expect(Branch::query()->where('code', 'PROD')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'cliente-admin@example.com')->value('force_password_change'))->toBeTrue();
});

it('solo sistemasuperadmin gestiona licencia interna', function () {
    $user = settingsUser(['settings.manage']);

    $payload = [
        'holder_name' => 'Cliente Ferreteria',
        'nit' => '1234567',
        'domain' => 'cliente.test',
        'license_key' => 'MRB-TEST-123',
        'support_until' => now()->addYear()->toDateString(),
        'allowed_modules' => ['sales', 'inventory'],
        'activation_mode' => 'offline',
        'status' => 'active',
        'notes' => null,
    ];

    $this->actingAs($user)
        ->post(route('settings.system.licenses.store'), $payload)
        ->assertForbidden();

    $role = Role::firstOrCreate(['name' => SystemRoles::SYSTEM_SUPERADMIN, 'guard_name' => 'web']);
    $role->syncPermissions(Permission::query()->where('guard_name', 'web')->pluck('name')->all());
    $user->syncRoles([$role->name]);

    $this->actingAs($user)
        ->post(route('settings.system.licenses.store'), $payload)
        ->assertRedirect(route('settings.system.index'));

    expect(ApplicationLicense::query()->where('license_key', 'MRB-TEST-123')->exists())->toBeTrue();
});

function fakeXlsxUpload(array $rows): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'xlsx-import-').'.xlsx';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheets><sheet name="Datos" sheetId="1" r:id="rId1" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', xlsxSheetXml($rows));
    $zip->close();

    return new UploadedFile($path, 'productos.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

function xlsxSheetXml(array $rows): string
{
    $xmlRows = collect($rows)->map(function (array $row, int $rowIndex) {
        $cells = collect($row)->map(function ($value, int $columnIndex) use ($rowIndex) {
            $reference = chr(65 + $columnIndex).($rowIndex + 1);
            $value = htmlspecialchars((string) $value, ENT_XML1);

            return "<c r=\"{$reference}\" t=\"inlineStr\"><is><t>{$value}</t></is></c>";
        })->implode('');

        return '<row r="'.($rowIndex + 1).'">'.$cells.'</row>';
    })->implode('');

    return '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$xmlRows.'</sheetData></worksheet>';
}
