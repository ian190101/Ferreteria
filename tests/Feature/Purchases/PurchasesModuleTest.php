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
use App\Modules\Purchases\Models\PurchaseOrderReceipt;
use App\Modules\Purchases\Models\Supplier;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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

it('ingresa compra por bobina y crea rollo fisico trazable', function () {
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
                'description' => 'Ingreso bobina',
            ]],
        ])
        ->assertRedirect();

    expect(ProductCoil::query()->where('barcode', 'COIL-COMPRA-001')->exists())->toBeTrue()
        ->and(InventoryMovement::query()->where('type', 'purchase_entry_coil')->exists())->toBeTrue();
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
