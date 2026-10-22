<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Inventory\Models\Thickness;
use App\Modules\Purchases\Models\Purchase;
use App\Modules\Purchases\Models\PurchaseItem;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Models\PurchaseOrderItem;
use App\Modules\Purchases\Models\PurchaseOrderReceipt;
use App\Modules\Purchases\Models\Supplier;
use App\Modules\Purchases\Services\PurchaseNumberService;
use App\Modules\Sales\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('recarga proveedores con una version antigua de Inertia sin generar un error del sistema', function () {
    $user = purchasesUser(['purchases.view']);
    $url = route('purchases.suppliers.index');

    $this->actingAs($user)->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => 'version-anterior-de-prueba',
    ])->get($url)
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', $url)
        ->assertHeaderMissing('X-System-Error-Code')
        ->assertSessionMissing('system_error');

    $version = app(\App\Http\Middleware\HandleInertiaRequests::class)
        ->version(\Illuminate\Http\Request::create($url));
    $this->withHeaders(['X-Inertia-Version' => $version ?? ''])->get($url)
        ->assertOk()
        ->assertHeader('X-Inertia', 'true')
        ->assertJsonPath('component', 'Purchases/Suppliers/Index')
        ->assertJsonPath('props.systemError', null);
});

function purchasesUser(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'compras-test', 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $branch = Branch::query()->create([
        'name' => 'Central',
        'code' => 'COMPRAS',
        'barcode' => 'BR-COMPRAS',
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);

    $user->assignRole($role);

    return $user;
}

it('asigna numeros correlativos al guardar compras sin aceptar numeros del cliente', function (string $status) {
    $user = purchasesUser(['purchases.view', 'purchases.manage']);
    $product = Product::query()->create([
        'name' => 'Producto con numeracion automatica',
        'sku' => 'COMP-AUTO',
        'barcode' => 'PR-COMP-AUTO',
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'is_active' => true,
    ]);
    $payload = [
        'branch_id' => $user->branch_id,
        'status' => $status,
        'items' => [['product_id' => $product->id, 'meters' => 2, 'unit_cost' => 5]],
    ];

    $this->actingAs($user)->post(route('purchases.store'), $payload)
        ->assertSessionHasNoErrors()->assertRedirect();
    $this->post(route('purchases.store'), [...$payload, 'document_number' => 'NUMERO-MANUAL'])
        ->assertSessionHasNoErrors()->assertRedirect();

    expect(Purchase::query()->orderBy('id')->pluck('document_number')->all())->toBe([
        "COMP-{$user->branch_id}-000001",
        "COMP-{$user->branch_id}-000002",
    ])->and(DocumentSequence::query()->where('document_type', 'purchase')->value('next_number'))->toBe(3);
})->with(['draft', 'received']);

it('conserva numeros historicos y separa los correlativos de cada sucursal', function () {
    $user = purchasesUser(['purchases.manage']);
    $purchase = Purchase::query()->create([
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'document_number' => "COMP-{$user->branch_id}-000001",
        'purchase_date' => now()->toDateString(),
        'status' => 'voided',
        'total_amount' => 0,
    ]);
    $purchase->delete();
    $otherBranch = Branch::query()->create(['name' => 'Otra sucursal', 'code' => 'OTRA-COMP', 'barcode' => 'BR-OTRA-COMP', 'is_active' => true]);
    $numbers = app(PurchaseNumberService::class);

    expect($numbers->next($user->branch_id))->toBe("COMP-{$user->branch_id}-000002")
        ->and($numbers->next($otherBranch->id))->toBe("COMP-{$otherBranch->id}-000001")
        ->and($numbers->next($user->branch_id))->toBe("COMP-{$user->branch_id}-000003");
});

it('revierte el correlativo si la transaccion de compra falla', function () {
    $user = purchasesUser(['purchases.manage']);
    $numbers = app(PurchaseNumberService::class);

    try {
        DB::transaction(function () use ($numbers, $user): void {
            $numbers->next($user->branch_id);
            throw new RuntimeException('Fallo de compra simulado');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Fallo de compra simulado');
    }

    expect($numbers->next($user->branch_id))->toBe("COMP-{$user->branch_id}-000001");
});

it('ingresa compra global y aumenta stock con conversion kg a metros', function () {
    $user = purchasesUser(['purchases.view', 'purchases.manage']);
    $supplier = Supplier::query()->create(['name' => 'Proveedor A', 'is_active' => true]);
    $thickness = Thickness::query()->create([
        'name' => '0.50 mm',
        'millimeters' => 0.5,
        'kg_to_meter_factor' => 10,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'thickness_id' => $thickness->id,
        'name' => 'Global',
        'sku' => 'GLOBAL-001',
        'barcode' => 'PR-GLOBAL-001',
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('purchases.store'), [
            'branch_id' => $user->branch_id,
            'supplier_id' => $supplier->id,
            'document_number' => 'COMP-001',
            'purchase_date' => now()->format('Y-m-d'),
            'status' => 'received',
            'items' => [[
                'product_id' => $product->id,
                'kilograms' => 5,
                'meters' => null,
                'unit_cost' => 2,
                'lot_number' => 'L-001',
                'coil_barcode' => null,
                'description' => 'Ingreso global',
            ]],
        ])
        ->assertRedirect();

    expect((float) ProductBranchStock::query()->where('product_id', $product->id)->value('available_meters'))->toBe(50.0)
        ->and(InventoryMovement::query()->where('type', 'purchase_entry_global')->exists())->toBeTrue();
});

it('ingresa compra por bobina trazable sin descripcion opcional', function () {
    $user = purchasesUser(['purchases.view', 'purchases.manage']);
    $product = Product::query()->create([
        'name' => 'Bobina compra',
        'sku' => 'BOB-COMPRA',
        'barcode' => 'PR-BOB-COMPRA',
        'inventory_tracking_mode' => Product::TRACKING_COIL,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('purchases.store'), [
            'branch_id' => $user->branch_id,
            'supplier_id' => null,
            'document_number' => 'COMP-BOB-001',
            'purchase_date' => now()->format('Y-m-d'),
            'status' => 'received',
            'items' => [[
                'product_id' => $product->id,
                'kilograms' => null,
                'meters' => 120,
                'unit_cost' => 3,
                'lot_number' => 'LOT-BOB',
                'coil_barcode' => 'COIL-COMPRA-001',
            ]],
        ])
        ->assertRedirect();

    expect(ProductCoil::query()->where('barcode', 'COIL-COMPRA-001')->exists())->toBeTrue()
        ->and(InventoryMovement::query()->where('type', 'purchase_entry_coil')->exists())->toBeTrue()
        ->and(PurchaseItem::query()->value('description'))->toBe('Bobina compra');
});

it('permite compra por lote sin numero de lote ni barcode', function () {
    $user = purchasesUser(['purchases.view', 'purchases.manage']);
    $product = Product::query()->create([
        'name' => 'Lote sin codigo',
        'sku' => 'LOTE-SIN-CODIGO',
        'barcode' => 'PR-LOTE-SIN-CODIGO',
        'inventory_tracking_mode' => Product::TRACKING_COIL,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('purchases.store'), [
            'branch_id' => $user->branch_id,
            'supplier_id' => null,
            'document_number' => 'COMP-LOTE-OPC-001',
            'purchase_date' => now()->format('Y-m-d'),
            'status' => 'received',
            'items' => [[
                'product_id' => $product->id,
                'purchase_stock_mode' => Product::TRACKING_COIL,
                'meters' => 75,
                'unit_cost' => 4,
                'lot_number' => null,
                'coil_barcode' => null,
                'description' => 'Ingreso trazable sin identificadores',
            ]],
        ])
        ->assertRedirect();

    $coil = ProductCoil::query()->where('product_id', $product->id)->firstOrFail();

    expect($coil->lot_number)->toBeNull()
        ->and($coil->barcode)->toBeNull()
        ->and((float) $coil->available_meters)->toBe(75.0);
});

it('muestra lotes existentes para reutilizarlos en compras', function () {
    $user = purchasesUser(['purchases.view', 'purchases.manage']);
    $product = Product::query()->create([
        'name' => 'Producto con lote previo',
        'sku' => 'LOTE-PREVIO',
        'barcode' => 'PR-LOTE-PREVIO',
        'inventory_tracking_mode' => Product::TRACKING_COIL,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);

    ProductBranchStock::query()->create([
        'branch_id' => $user->branch_id,
        'product_id' => $product->id,
        'available_meters' => 0,
        'reserved_meters' => 0,
        'is_enabled' => true,
    ]);
    ProductCoil::query()->create([
        'branch_id' => $user->branch_id,
        'product_id' => $product->id,
        'barcode' => 'COIL-LOTE-COMUN',
        'lot_number' => 'LOTE-COMUN-2026',
        'initial_meters' => 10,
        'available_meters' => 10,
        'status' => 'available',
    ]);

    $this->actingAs($user)
        ->get(route('purchases.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('lotOptions.0', 'LOTE-COMUN-2026')
        );
});

it('bloquea compra por lote si el producto existente esta configurado como stock general', function () {
    $user = purchasesUser(['purchases.view', 'purchases.manage']);
    $product = Product::query()->create([
        'name' => 'Producto global seguro',
        'sku' => 'GLOBAL-SEGURO',
        'barcode' => 'PR-GLOBAL-SEGURO',
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->from(route('purchases.create'))
        ->post(route('purchases.store'), [
            'branch_id' => $user->branch_id,
            'supplier_id' => null,
            'document_number' => 'COMP-MODO-001',
            'purchase_date' => now()->format('Y-m-d'),
            'status' => 'received',
            'items' => [[
                'product_id' => $product->id,
                'purchase_stock_mode' => Product::TRACKING_COIL,
                'meters' => 10,
                'unit_cost' => 5,
                'lot_number' => 'LOT-FORZADO',
                'coil_barcode' => 'COIL-FORZADO-001',
                'description' => 'Intento por lote',
            ]],
        ])
        ->assertRedirect(route('purchases.create'))
        ->assertSessionHasErrors('items.0.purchase_stock_mode');

    expect(ProductCoil::query()->where('barcode', 'COIL-FORZADO-001')->exists())->toBeFalse();
});

it('lista y filtra proveedores con conteo de compras', function () {
    $user = purchasesUser(['purchases.view']);
    Supplier::query()->create([
        'name' => 'Proveedor Acero',
        'tax_id' => '123456',
        'phone' => '70000001',
        'email' => 'acero@example.com',
        'is_active' => true,
    ]);
    Supplier::query()->create([
        'name' => 'Proveedor Inactivo',
        'tax_id' => '999999',
        'is_active' => false,
    ]);

    $this->actingAs($user)
        ->get(route('purchases.suppliers.index', ['search' => 'acero', 'is_active' => 1]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Purchases/Suppliers/Index', false)
            ->has('suppliers.data', 1)
            ->where('suppliers.data.0.name', 'Proveedor Acero')
            ->where('suppliers.data.0.purchases_count', 0)
        );
});

it('crea actualiza y desactiva proveedores', function () {
    $user = purchasesUser(['purchases.view', 'purchases.manage']);

    $this->actingAs($user)
        ->post(route('purchases.suppliers.store'), [
            'name' => 'Proveedor Inicial',
            'tax_id' => '777',
            'phone' => '70000002',
            'email' => 'proveedor@example.com',
            'is_active' => true,
        ])
        ->assertRedirect(route('purchases.suppliers.index'));

    $supplier = Supplier::query()->where('tax_id', '777')->firstOrFail();

    $this->actingAs($user)
        ->put(route('purchases.suppliers.update', $supplier->id), [
            'name' => 'Proveedor Actualizado',
            'tax_id' => '888',
            'phone' => '70000003',
            'email' => 'actualizado@example.com',
            'is_active' => true,
        ])
        ->assertRedirect(route('purchases.suppliers.index'));

    $supplier->refresh();

    expect($supplier->name)->toBe('Proveedor Actualizado')
        ->and($supplier->tax_id)->toBe('888');

    $this->actingAs($user)
        ->delete(route('purchases.suppliers.destroy', $supplier->id))
        ->assertRedirect(route('purchases.suppliers.index'));

    $supplier->refresh();

    expect($supplier->is_active)->toBeFalse()
        ->and($supplier->trashed())->toBeTrue();
});

it('bloquea proveedores con nit duplicado', function () {
    $user = purchasesUser(['purchases.view', 'purchases.manage']);

    Supplier::query()->create([
        'name' => 'Proveedor A',
        'tax_id' => '111',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->from(route('purchases.suppliers.index'))
        ->post(route('purchases.suppliers.store'), [
            'name' => 'Proveedor B',
            'tax_id' => '111',
            'phone' => null,
            'email' => null,
            'is_active' => true,
        ])
        ->assertRedirect(route('purchases.suppliers.index'))
        ->assertSessionHasErrors('tax_id');
});

it('muestra estado de proveedor con compras e items paginados', function () {
    $user = purchasesUser(['purchases.view']);
    $supplier = Supplier::query()->create([
        'name' => 'Proveedor Acero',
        'tax_id' => '12345',
        'phone' => '70000001',
        'email' => 'ventas@acero.test',
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'name' => 'Calamina proveedor',
        'sku' => 'PROV-001',
        'barcode' => 'PR-PROV-001',
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);
    $receivedPurchase = Purchase::query()->create([
        'branch_id' => $user->branch_id,
        'supplier_id' => $supplier->id,
        'user_id' => $user->id,
        'document_number' => 'COMP-PROV-001',
        'purchase_date' => now()->subDay()->toDateString(),
        'total_amount' => 500,
        'status' => 'received',
    ]);
    Purchase::query()->create([
        'branch_id' => $user->branch_id,
        'supplier_id' => $supplier->id,
        'user_id' => $user->id,
        'document_number' => 'COMP-PROV-002',
        'purchase_date' => now()->toDateString(),
        'total_amount' => 300,
        'status' => 'pending',
    ]);
    PurchaseItem::query()->create([
        'purchase_id' => $receivedPurchase->id,
        'product_id' => $product->id,
        'kilograms' => 10,
        'meters' => 120,
        'unit_cost' => 4.1667,
        'conversion_factor' => 12,
        'lot_number' => 'LOTE-PROV',
        'description' => 'Calamina proveedor',
    ]);

    $this->actingAs($user)
        ->get(route('purchases.suppliers.statement', $supplier->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Purchases/Suppliers/Statement', false)
            ->where('supplier.name', 'Proveedor Acero')
            ->where('metrics.purchases_count', 2)
            ->where('metrics.purchases_total', 800)
            ->where('metrics.received_total', 500)
            ->where('metrics.pending_total', 300)
            ->where('metrics.meters_total', 120)
            ->where('metrics.kilograms_total', 10)
            ->has('purchases.data', 2)
            ->has('items.data', 1)
        );
});

it('crea orden de compra aprobada sin mover inventario', function () {
    $user = purchasesUser(['purchases.view', 'purchases.manage']);
    $supplier = Supplier::query()->create(['name' => 'Proveedor orden', 'is_active' => true]);
    $thickness = Thickness::query()->create([
        'name' => '0.40 mm',
        'millimeters' => 0.4,
        'kg_to_meter_factor' => 8,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'thickness_id' => $thickness->id,
        'name' => 'Global orden',
        'sku' => 'ORD-GLOBAL',
        'barcode' => 'PR-ORD-GLOBAL',
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('purchases.orders.store'), [
            'branch_id' => $user->branch_id,
            'supplier_id' => $supplier->id,
            'order_number' => 'OC-001',
            'ordered_at' => now()->format('Y-m-d'),
            'expected_at' => now()->addDays(3)->format('Y-m-d'),
            'status' => PurchaseOrder::STATUS_APPROVED,
            'notes' => 'Comprar para reposicion',
            'items' => [[
                'product_id' => $product->id,
                'kilograms' => 5,
                'meters' => null,
                'unit_cost' => 2,
                'lot_number' => 'L-OC-001',
                'coil_barcode' => null,
                'description' => 'Orden global',
            ]],
        ])
        ->assertRedirect(route('purchases.orders.index'));

    $order = PurchaseOrder::query()->with('items')->where('order_number', 'OC-001')->firstOrFail();

    expect($order->status)->toBe(PurchaseOrder::STATUS_APPROVED)
        ->and((float) $order->total_amount)->toBe(80.0)
        ->and((float) $order->items->first()->meters)->toBe(40.0)
        ->and(ProductBranchStock::query()->where('product_id', $product->id)->exists())->toBeFalse();
});

it('convierte orden aprobada en compra recibida y aumenta inventario', function () {
    $user = purchasesUser(['purchases.view', 'purchases.manage']);
    $supplier = Supplier::query()->create(['name' => 'Proveedor conversion', 'is_active' => true]);
    $product = Product::query()->create([
        'name' => 'Producto conversion',
        'sku' => 'ORD-CONV',
        'barcode' => 'PR-ORD-CONV',
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);
    $order = PurchaseOrder::query()->create([
        'branch_id' => $user->branch_id,
        'supplier_id' => $supplier->id,
        'user_id' => $user->id,
        'approved_by' => $user->id,
        'order_number' => 'OC-002',
        'ordered_at' => now()->toDateString(),
        'total_amount' => 300,
        'status' => PurchaseOrder::STATUS_APPROVED,
        'approved_at' => now(),
    ]);
    $order->items()->create([
        'product_id' => $product->id,
        'kilograms' => null,
        'meters' => 100,
        'unit_cost' => 3,
        'conversion_factor' => null,
        'lot_number' => 'L-OC-002',
        'description' => 'Conversion global',
    ]);

    $this->actingAs($user)
        ->post(route('purchases.orders.convert', $order->id))
        ->assertRedirect();

    $order->refresh();
    $purchase = Purchase::query()->where('document_number', 'like', 'REC-OC-002-%')->firstOrFail();

    expect($order->status)->toBe(PurchaseOrder::STATUS_CONVERTED)
        ->and($order->converted_purchase_id)->toBe($purchase->id)
        ->and((float) ProductBranchStock::query()->where('product_id', $product->id)->value('available_meters'))->toBe(100.0)
        ->and(InventoryMovement::query()->where('type', 'purchase_order_receipt_global')->exists())->toBeTrue();
});

it('recibe parcialmente orden de compra y mantiene saldo pendiente', function () {
    $user = purchasesUser(['purchases.view', 'purchases.manage']);
    $supplier = Supplier::query()->create(['name' => 'Proveedor parcial', 'is_active' => true]);
    $product = Product::query()->create([
        'name' => 'Producto parcial',
        'sku' => 'ORD-PARC',
        'barcode' => 'PR-ORD-PARC',
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);
    $order = PurchaseOrder::query()->create([
        'branch_id' => $user->branch_id,
        'supplier_id' => $supplier->id,
        'user_id' => $user->id,
        'approved_by' => $user->id,
        'order_number' => 'OC-003',
        'ordered_at' => now()->toDateString(),
        'total_amount' => 200,
        'status' => PurchaseOrder::STATUS_APPROVED,
        'approved_at' => now(),
    ]);
    $item = $order->items()->create([
        'product_id' => $product->id,
        'kilograms' => null,
        'meters' => 100,
        'unit_cost' => 2,
        'conversion_factor' => null,
        'lot_number' => 'L-OC-003',
        'description' => 'Recepcion parcial',
    ]);

    $this->actingAs($user)
        ->post(route('purchases.orders.receipts.store', $order->id), [
            'received_at' => now()->toDateString(),
            'notes' => 'Entrega parcial proveedor',
            'items' => [[
                'purchase_order_item_id' => $item->id,
                'meters' => 40,
                'kilograms' => null,
                'coil_barcode' => null,
            ]],
        ])
        ->assertRedirect();

    $order->refresh();
    $item->refresh();

    expect($order->status)->toBe(PurchaseOrder::STATUS_PARTIAL_RECEIVED)
        ->and((float) $item->received_meters)->toBe(40.0)
        ->and((float) ProductBranchStock::query()->where('product_id', $product->id)->value('available_meters'))->toBe(40.0)
        ->and(PurchaseOrderReceipt::query()->where('purchase_order_id', $order->id)->count())->toBe(1)
        ->and(InventoryMovement::query()->where('type', 'purchase_order_receipt_global')->exists())->toBeTrue();
});

it('bloquea recepcion mayor al saldo pendiente de la orden', function () {
    $user = purchasesUser(['purchases.view', 'purchases.manage']);
    $product = Product::query()->create([
        'name' => 'Producto bloqueo',
        'sku' => 'ORD-BLOQ',
        'barcode' => 'PR-ORD-BLOQ',
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);
    $order = PurchaseOrder::query()->create([
        'branch_id' => $user->branch_id,
        'supplier_id' => null,
        'user_id' => $user->id,
        'approved_by' => $user->id,
        'order_number' => 'OC-004',
        'ordered_at' => now()->toDateString(),
        'total_amount' => 150,
        'status' => PurchaseOrder::STATUS_PARTIAL_RECEIVED,
        'approved_at' => now(),
    ]);
    $item = $order->items()->create([
        'product_id' => $product->id,
        'kilograms' => null,
        'meters' => 50,
        'received_meters' => 20,
        'unit_cost' => 3,
        'conversion_factor' => null,
        'lot_number' => 'L-OC-004',
        'description' => 'Bloqueo excedente',
    ]);

    $this->actingAs($user)
        ->from(route('purchases.orders.receive', $order->id))
        ->post(route('purchases.orders.receipts.store', $order->id), [
            'received_at' => now()->toDateString(),
            'items' => [[
                'purchase_order_item_id' => $item->id,
                'meters' => 40,
                'kilograms' => null,
                'coil_barcode' => null,
            ]],
        ])
        ->assertRedirect(route('purchases.orders.receive', $order->id))
        ->assertSessionHasErrors('items.0.meters');

    expect(PurchaseOrderReceipt::query()->where('purchase_order_id', $order->id)->exists())->toBeFalse();
});

it('permite anular una compra desde la tabla de compras revirtiendo stock global', function () {
    $user = purchasesUser(['purchases.view', 'purchases.manage']);
    $product = Product::query()->create([
        'name' => 'Producto Compra Global',
        'sku' => 'COMPRA-GLOB-01',
        'barcode' => 'BC-GLOB-01',
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);

    $purchase = Purchase::query()->create([
        'branch_id' => $user->branch_id,
        'supplier_id' => null,
        'user_id' => $user->id,
        'document_number' => 'FAC-TEST-001',
        'purchase_date' => now()->toDateString(),
        'total_amount' => 300,
        'paid_amount' => 0,
        'balance_due' => 300,
        'payment_status' => 'unpaid',
        'status' => 'received',
    ]);

    $purchase->items()->create([
        'product_id' => $product->id,
        'meters' => 30,
        'unit_cost' => 10,
        'description' => 'Test global',
    ]);

    ProductBranchStock::query()->create([
        'branch_id' => $user->branch_id,
        'product_id' => $product->id,
        'available_meters' => 50,
        'reserved_meters' => 0,
        'is_enabled' => true,
    ]);

    $this->actingAs($user)
        ->patch(route('purchases.void', $purchase->id), [
            'reason' => 'Mercaderia defectuosa',
        ])
        ->assertRedirect();

    $purchase->refresh();
    expect($purchase->status)->toBe('voided')
        ->and($purchase->payment_status)->toBe('voided')
        ->and((float) $purchase->balance_due)->toBe(0.0);

    $stock = ProductBranchStock::query()
        ->where('branch_id', $user->branch_id)
        ->where('product_id', $product->id)
        ->first();

    expect((float) $stock->available_meters)->toBe(20.0);

    $movement = InventoryMovement::query()
        ->where('source_type', Purchase::class)
        ->where('source_id', $purchase->id)
        ->where('type', 'purchase_void_global')
        ->first();

    expect($movement)->not->toBeNull()
        ->and((float) $movement->meters_delta)->toBe(-30.0);
});

it('permite anular una compra de bobinas revirtiendo el estado de la bobina', function () {
    $user = purchasesUser(['purchases.view', 'purchases.manage']);
    $product = Product::query()->create([
        'name' => 'Calamina Bobina',
        'sku' => 'CAL-BOB-01',
        'barcode' => 'BC-CAL-BOB-01',
        'inventory_tracking_mode' => Product::TRACKING_COIL,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);

    $coil = ProductCoil::query()->create([
        'branch_id' => $user->branch_id,
        'product_id' => $product->id,
        'barcode' => 'BOB-TEST-001',
        'lot_number' => 'LOTE-001',
        'initial_meters' => 100,
        'available_meters' => 100,
        'initial_kg' => 1000,
        'status' => 'available',
    ]);

    $purchase = Purchase::query()->create([
        'branch_id' => $user->branch_id,
        'supplier_id' => null,
        'user_id' => $user->id,
        'document_number' => 'FAC-BOB-001',
        'purchase_date' => now()->toDateString(),
        'total_amount' => 1000,
        'paid_amount' => 0,
        'balance_due' => 1000,
        'payment_status' => 'unpaid',
        'status' => 'received',
    ]);

    $purchase->items()->create([
        'product_id' => $product->id,
        'product_coil_id' => $coil->id,
        'coil_barcode' => $coil->barcode,
        'meters' => 100,
        'unit_cost' => 10,
        'description' => 'Test bobina',
    ]);

    $this->actingAs($user)
        ->patch(route('purchases.void', $purchase->id), [
            'reason' => 'Proveedor cancelo envio',
        ])
        ->assertRedirect();

    $purchase->refresh();
    expect($purchase->status)->toBe('voided');

    $voidedCoil = ProductCoil::withTrashed()->find($coil->id);
    expect($voidedCoil->trashed())->toBeTrue()
        ->and($voidedCoil->status)->toBe('voided')
        ->and((float) $voidedCoil->available_meters)->toBe(0.0);
});

it('bloquea anular una compra ya anulada', function () {
    $user = purchasesUser(['purchases.view', 'purchases.manage']);
    $purchase = Purchase::query()->create([
        'branch_id' => $user->branch_id,
        'supplier_id' => null,
        'user_id' => $user->id,
        'document_number' => 'FAC-ALREADY-VOID',
        'purchase_date' => now()->toDateString(),
        'total_amount' => 100,
        'paid_amount' => 0,
        'balance_due' => 0,
        'payment_status' => 'voided',
        'status' => 'voided',
    ]);

    $this->actingAs($user)
        ->patch(route('purchases.void', $purchase->id), [
            'reason' => 'Intento doble anulacion',
        ])
        ->assertSessionHasErrors('purchase');
});

it('revierte estado y metros recibidos de orden de compra al anular la compra recibida', function () {
    $user = purchasesUser(['purchases.view', 'purchases.manage']);
    $supplier = Supplier::query()->create(['name' => 'Proveedor Convertir Y Anular', 'is_active' => true]);
    $product = Product::query()->create([
        'name' => 'Calamina Orden Revertir',
        'sku' => 'ORD-REV-001',
        'barcode' => 'PR-ORD-REV-001',
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);

    ProductBranchStock::query()->create([
        'branch_id' => $user->branch_id,
        'product_id' => $product->id,
        'available_meters' => 0,
        'reserved_meters' => 0,
    ]);

    $order = PurchaseOrder::query()->create([
        'branch_id' => $user->branch_id,
        'supplier_id' => $supplier->id,
        'user_id' => $user->id,
        'order_number' => 'OC-REV-001',
        'ordered_at' => now()->toDateString(),
        'total_amount' => 400,
        'status' => PurchaseOrder::STATUS_APPROVED,
    ]);

    $orderItem = PurchaseOrderItem::query()->create([
        'purchase_order_id' => $order->id,
        'product_id' => $product->id,
        'meters' => 80,
        'unit_cost' => 5,
        'line_total' => 400,
        'received_meters' => 0,
    ]);

    // Recibir la orden
    $this->actingAs($user)
        ->post(route('purchases.orders.receipts.store', $order->id), [
            'received_at' => now()->toDateString(),
            'items' => [[
                'purchase_order_item_id' => $orderItem->id,
                'meters' => 80,
            ]],
            'notes' => 'Recepcion completa',
        ])
        ->assertRedirect();

    $order->refresh();
    expect($order->status)->toBe(PurchaseOrder::STATUS_CONVERTED);

    $purchase = Purchase::query()->where('document_number', 'like', 'REC-OC-REV-001-%')->firstOrFail();
    expect($purchase->status)->toBe('received');

    $stock = ProductBranchStock::query()->where('branch_id', $user->branch_id)->where('product_id', $product->id)->firstOrFail();
    expect((float) $stock->available_meters)->toBe(80.0);

    // Anular la compra
    $this->actingAs($user)
        ->patch(route('purchases.void', $purchase->id), [
            'reason' => 'Mercaderia defectuosa devuelta a proveedor',
        ])
        ->assertRedirect();

    $purchase->refresh();
    $order->refresh();
    $orderItem->refresh();
    $stock->refresh();

    expect($purchase->status)->toBe('voided')
        ->and($purchase->balance_due)->toBe('0.00')
        ->and((float) $stock->available_meters)->toBe(0.0)
        ->and($order->status)->toBe(PurchaseOrder::STATUS_APPROVED)
        ->and((float) $orderItem->received_meters)->toBe(0.0)
        ->and(InventoryMovement::query()->where('type', 'purchase_void_global')->where('source_id', $purchase->id)->exists())->toBeTrue();
});
