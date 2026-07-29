<?php

use App\Models\User;
use App\Modules\Billing\Models\SiatBranchSetting;
use App\Modules\Billing\Models\SiatCufd;
use App\Modules\Billing\Models\SiatCuis;
use App\Modules\Billing\Models\SiatInvoice;
use App\Modules\Billing\Models\SiatProductMapping;
use App\Modules\Billing\Services\SiatCufGenerator;
use App\Modules\Billing\Services\SiatXmlValidator;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\Currency;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use App\Support\SystemRoles;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function billingUser(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'facturacion-test', 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $branch = Branch::query()->create([
        'name' => 'Sucursal Fiscal',
        'code' => 'FISCAL',
        'barcode' => 'BR-FISCAL',
        'phone' => '70000001',
        'secondary_phone' => '70000002',
        'point_of_sale_name' => 'Caja fiscal',
        'address' => 'Av. de prueba 123',
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);

    $user->assignRole($role);

    return $user;
}

it('configura SIAT, solicita CUIS/CUFD demo y emite factura desde nota de venta', function () {
    $user = billingUser(['billing.view', 'billing.manage', 'sales.view']);
    $user->assignRole(Role::firstOrCreate(['name' => SystemRoles::SYSTEM_SUPERADMIN, 'guard_name' => 'web']));

    BusinessProfile::query()->create([
        'name' => 'Perfil fiscal demo',
        'business_type' => 'hardware_store',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'modules' => ['billing' => true, 'sales_notes' => true],
            'billing' => [
                'enabled' => true,
                'invoice_flow' => 'choose_per_sale',
                'issue_timing' => 'manual',
                'issue_from' => 'manual_choice',
            ],
        ]),
        'applied_at' => now(),
        'applied_by' => $user->id,
    ]);
    Cache::flush();

    $this->actingAs($user)
        ->post(route('billing.settings.store'), [
            'branch_id' => $user->branch_id,
            'nit' => '123456789',
            'business_name' => 'Empresa Demo',
            'municipality' => 'Santa Cruz',
            'phone' => '70000001',
            'system_code' => 'SYS-DEMO',
            'environment_code' => SiatBranchSetting::ENVIRONMENT_PILOT,
            'modality_code' => SiatBranchSetting::MODALITY_COMPUTERIZED,
            'emission_type_code' => 1,
            'invoice_type_code' => 1,
            'document_sector_code' => 1,
            'siat_branch_code' => 0,
            'point_of_sale_code' => 0,
            'economic_activity_code' => 471100,
            'sin_product_code' => 99100,
            'token' => 'token-demo',
            'mock_siat' => true,
            'is_active' => true,
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('billing.codes.cuis'), ['branch_id' => $user->branch_id])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('billing.codes.cufd'), ['branch_id' => $user->branch_id])
        ->assertRedirect();

    expect(SiatCuis::query()->where('branch_id', $user->branch_id)->exists())->toBeTrue()
        ->and(SiatCufd::query()->where('branch_id', $user->branch_id)->exists())->toBeTrue();

    $currency = Currency::query()->create([
        'name' => 'Bolivianos',
        'code' => 'BOB',
        'symbol' => 'Bs',
        'exchange_rate_to_bob' => 1,
        'is_base' => true,
        'is_active' => true,
    ]);

    $product = Product::query()->create([
        'name' => 'Casco de seguridad',
        'sku' => 'CASCO-001',
        'barcode' => 'PR-CASCO-001',
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'base_unit' => 'unidad',
        'purchase_price' => 30,
        'sale_price' => 50,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);

    SiatProductMapping::query()->create([
        'product_id' => $product->id,
        'economic_activity_code' => 471100,
        'sin_product_code' => 99100,
        'unit_measure_code' => 58,
        'fiscal_description' => 'Casco de seguridad',
        'is_invoiceable' => true,
    ]);

    $sale = Sale::query()->create([
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'currency_id' => $currency->id,
        'receipt_number' => 'NV-FACT-001',
        'document_type' => 'sale_note',
        'customer_name' => 'Cliente Factura',
        'customer_document' => '1234567',
        'sold_at' => now(),
        'exchange_rate_to_bob' => 1,
        'subtotal' => 100,
        'discount_total' => 0,
        'advance_percentage' => 0,
        'advance_amount' => 0,
        'balance_due' => 100,
        'total' => 100,
        'status' => 'pending_payment',
        'terms' => 'Factura demo',
    ]);

    SaleItem::query()->create([
        'sale_id' => $sale->id,
        'product_id' => $product->id,
        'description' => 'Casco de seguridad',
        'unit_label' => 'unidad',
        'display_quantity' => 2,
        'display_unit_label' => 'unidad',
        'calculation_mode' => 'direct',
        'meters' => 2,
        'unit_price' => 50,
        'discount_amount' => 0,
        'total' => 100,
    ]);

    $this->actingAs($user)
        ->post(route('billing.sales.issue', $sale))
        ->assertRedirect();

    $invoice = SiatInvoice::query()->firstOrFail();

    expect($invoice->status)->toBe(SiatInvoice::STATUS_VALIDATED)
        ->and($invoice->reception_code)->not->toBeNull()
        ->and($invoice->xml)->toContain('facturaComputarizadaCompraVenta')
        ->and($invoice->items()->count())->toBe(1);
});

it('vence CUIS y CUFD activos cuando cambian credenciales fiscales criticas', function () {
    $user = billingUser(['billing.view', 'billing.manage']);
    BusinessProfile::query()->create([
        'name' => 'Perfil fiscal credenciales',
        'business_type' => 'hardware_store',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'modules' => ['billing' => true],
            'billing' => ['enabled' => true],
        ]),
        'applied_at' => now(),
        'applied_by' => $user->id,
    ]);
    Cache::flush();

    $payload = [
        'branch_id' => $user->branch_id,
        'nit' => '123456789',
        'business_name' => 'Empresa Demo',
        'municipality' => 'Santa Cruz',
        'phone' => '70000001',
        'system_code' => 'SYS-DEMO',
        'environment_code' => SiatBranchSetting::ENVIRONMENT_PILOT,
        'modality_code' => SiatBranchSetting::MODALITY_COMPUTERIZED,
        'emission_type_code' => 1,
        'invoice_type_code' => 1,
        'document_sector_code' => 1,
        'siat_branch_code' => 0,
        'point_of_sale_code' => 0,
        'economic_activity_code' => 471100,
        'sin_product_code' => 99100,
        'token' => 'token-demo',
        'mock_siat' => true,
        'is_active' => true,
    ];

    $this->actingAs($user)->post(route('billing.settings.store'), $payload)->assertRedirect();

    $cuis = SiatCuis::query()->create([
        'branch_id' => $user->branch_id,
        'code' => 'CUIS-ACTIVO',
        'issued_at' => now(),
        'expires_at' => now()->addYear(),
        'status' => SiatCuis::STATUS_ACTIVE,
    ]);

    SiatCufd::query()->create([
        'branch_id' => $user->branch_id,
        'siat_cuis_id' => $cuis->id,
        'code' => 'CUFD-ACTIVO',
        'control_code' => 'CONTROL',
        'address' => 'Av. Fiscal',
        'valid_from' => now(),
        'valid_until' => now()->addDay(),
        'status' => SiatCufd::STATUS_ACTIVE,
    ]);

    $this->actingAs($user)
        ->post(route('billing.settings.store'), [
            ...$payload,
            'nit' => '987654321',
            'token' => 'token-produccion',
        ])
        ->assertRedirect();

    expect(SiatCuis::query()->where('branch_id', $user->branch_id)->where('status', SiatCuis::STATUS_ACTIVE)->count())->toBe(0)
        ->and(SiatCufd::query()->where('branch_id', $user->branch_id)->where('status', SiatCufd::STATUS_ACTIVE)->count())->toBe(0)
        ->and(SiatCuis::query()->where('branch_id', $user->branch_id)->where('status', SiatCuis::STATUS_EXPIRED)->count())->toBe(1)
        ->and(SiatCufd::query()->where('branch_id', $user->branch_id)->where('status', SiatCufd::STATUS_EXPIRED)->count())->toBe(1);
});

it('bloquea activar modalidad electronica mientras no exista firma XML-DSig real', function () {
    $user = billingUser(['billing.view', 'billing.manage']);

    $this->actingAs($user)
        ->post(route('billing.settings.store'), [
            'branch_id' => $user->branch_id,
            'nit' => '123456789',
            'business_name' => 'Empresa Demo',
            'municipality' => 'Santa Cruz',
            'phone' => '70000001',
            'system_code' => 'SYS-DEMO',
            'environment_code' => SiatBranchSetting::ENVIRONMENT_PILOT,
            'modality_code' => SiatBranchSetting::MODALITY_ELECTRONIC,
            'emission_type_code' => 1,
            'invoice_type_code' => 1,
            'document_sector_code' => 1,
            'siat_branch_code' => 0,
            'point_of_sale_code' => 0,
            'economic_activity_code' => 471100,
            'sin_product_code' => 99100,
            'token' => 'token-demo',
            'mock_siat' => false,
            'is_active' => true,
        ])
        ->assertSessionHasErrors('modality_code');
});

it('valida el XML de compra venta contra el esquema local antes de enviar', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<facturaComputarizadaCompraVenta xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <cabecera>
    <nitEmisor>123456789</nitEmisor>
    <razonSocialEmisor>Empresa Demo</razonSocialEmisor>
    <municipio>Santa Cruz</municipio>
    <telefono xsi:nil="true"/>
    <numeroFactura>1</numeroFactura>
    <cuf>ABC123</cuf>
    <cufd>CUFD123</cufd>
    <codigoSucursal>0</codigoSucursal>
    <direccion>Av. Demo</direccion>
    <codigoPuntoVenta xsi:nil="true"/>
    <fechaEmision>2026-07-24T10:00:00.000</fechaEmision>
    <nombreRazonSocial>Cliente Demo</nombreRazonSocial>
    <codigoTipoDocumentoIdentidad>1</codigoTipoDocumentoIdentidad>
    <numeroDocumento>1234567</numeroDocumento>
    <complemento xsi:nil="true"/>
    <codigoCliente>1234567</codigoCliente>
    <codigoMetodoPago>1</codigoMetodoPago>
    <numeroTarjeta xsi:nil="true"/>
    <montoTotal>100.00</montoTotal>
    <montoTotalSujetoIva>100.00</montoTotalSujetoIva>
    <montoGiftCard xsi:nil="true"/>
    <descuentoAdicional xsi:nil="true"/>
    <codigoExcepcion>0</codigoExcepcion>
    <cafc xsi:nil="true"/>
    <codigoMoneda>1</codigoMoneda>
    <tipoCambio>1.000000</tipoCambio>
    <montoTotalMoneda>100.00</montoTotalMoneda>
    <leyenda>Ley N 453</leyenda>
    <usuario>ian</usuario>
    <codigoDocumentoSector>1</codigoDocumentoSector>
  </cabecera>
  <detalle>
    <actividadEconomica>471100</actividadEconomica>
    <codigoProductoSin>99100</codigoProductoSin>
    <codigoProducto>CASCO-001</codigoProducto>
    <descripcion>Casco</descripcion>
    <cantidad>2.0000</cantidad>
    <unidadMedida>58</unidadMedida>
    <precioUnitario>50.0000</precioUnitario>
    <montoDescuento xsi:nil="true"/>
    <subTotal>100.00</subTotal>
    <numeroSerie xsi:nil="true"/>
    <numeroImei xsi:nil="true"/>
  </detalle>
</facturaComputarizadaCompraVenta>
XML;

    app(SiatXmlValidator::class)->validateCompraVenta($xml);

    expect(true)->toBeTrue();
});

it('genera el CUF con precision completa segun el ejemplo oficial SIAT', function () {
    $setting = new SiatBranchSetting([
        'nit' => '123456789',
        'siat_branch_code' => 0,
        'modality_code' => 1,
        'emission_type_code' => 1,
        'invoice_type_code' => 1,
        'document_sector_code' => 1,
        'point_of_sale_code' => 0,
    ]);

    $cuf = app(SiatCufGenerator::class)->generate(
        $setting,
        1,
        Carbon::parse('2019-01-13 16:37:21.231'),
        'A19E23EF34124CD',
    );

    expect($cuf)->toBe('8727F63A15F8976591FDDE5B387C5D015A29E06A1A19E23EF34124CD');
});

it('solo sistemasuperadmin puede aplicar presets internos SIAT', function () {
    $user = billingUser(['billing.view', 'billing.manage']);

    $this->actingAs($user)
        ->post(route('billing.settings.internal-preset'), [
            'branch_id' => $user->branch_id,
            'preset' => 'local_mock_compra_venta',
        ])
        ->assertForbidden();

    $user->assignRole(Role::firstOrCreate(['name' => SystemRoles::SYSTEM_SUPERADMIN, 'guard_name' => 'web']));

    $this->actingAs($user)
        ->post(route('billing.settings.internal-preset'), [
            'branch_id' => $user->branch_id,
            'preset' => 'local_mock_compra_venta',
        ])
        ->assertRedirect();

    expect(SiatBranchSetting::query()->where('branch_id', $user->branch_id)->where('system_code', 'SYS-DEMO-LOCAL')->exists())->toBeTrue();
});

it('solo sistemasuperadmin puede registrar CUIS y CUFD manuales de prueba', function () {
    $user = billingUser(['billing.view', 'billing.manage']);

    $this->actingAs($user)
        ->post(route('billing.settings.internal-codes'), [
            'branch_id' => $user->branch_id,
            'cuis' => 'CUIS-MANUAL',
            'cufd' => 'CUFD-MANUAL',
            'control_code' => 'CONTROL-MANUAL',
        ])
        ->assertForbidden();

    $user->assignRole(Role::firstOrCreate(['name' => SystemRoles::SYSTEM_SUPERADMIN, 'guard_name' => 'web']));

    $this->actingAs($user)
        ->post(route('billing.settings.internal-codes'), [
            'branch_id' => $user->branch_id,
            'cuis' => 'CUIS-MANUAL',
            'cufd' => 'CUFD-MANUAL',
            'control_code' => 'CONTROL-MANUAL',
            'address' => 'Direccion piloto',
        ])
        ->assertRedirect();

    expect(SiatCuis::query()->where('branch_id', $user->branch_id)->where('code', 'CUIS-MANUAL')->exists())->toBeTrue()
        ->and(SiatCufd::query()->where('branch_id', $user->branch_id)->where('code', 'CUFD-MANUAL')->exists())->toBeTrue();
});
