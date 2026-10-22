<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Purchases\Models\Purchase;
use App\Modules\Sales\Models\Sale;
use App\Modules\SystemSuperadmin\Services\DatabasePurgeService;
use App\Support\SystemRoles;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

function createSuperadminUser(): User
{
    $role = Role::firstOrCreate(['name' => SystemRoles::SYSTEM_SUPERADMIN, 'guard_name' => 'web']);

    $branch = Branch::query()->firstOrCreate([
        'code' => 'PURGE-BR',
    ], [
        'name' => 'Sucursal Purga',
        'barcode' => 'BR-PURGE',
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);

    $user->assignRole($role);

    return $user;
}

function createRegularAdminUser(): User
{
    $branch = Branch::query()->firstOrCreate([
        'code' => 'PURGE-BR',
    ], [
        'name' => 'Sucursal Purga',
        'barcode' => 'BR-PURGE',
        'is_active' => true,
    ]);

    return User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);
}

it('permite al usuario sistemasuperadmin ver la pantalla de purga de base de datos con estadisticas', function () {
    $superadmin = createSuperadminUser();

    $this->actingAs($superadmin)
        ->get(route('system-superadmin.database-purge.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SystemSuperadmin/DatabasePurge/Index', false)
            ->has('stats.modules')
            ->has('stats.summary')
            ->has('modulesCatalog')
            ->has('protectedTables')
        );
});

it('bloquea el acceso a la pantalla de purga a usuarios sin rol sistemasuperadmin', function () {
    $regular = createRegularAdminUser();

    $this->actingAs($regular)
        ->get(route('system-superadmin.database-purge.index'))
        ->assertForbidden();
});

it('ejecuta hard delete permanente de tablas seleccionadas', function () {
    $superadmin = createSuperadminUser();

    // Crear ventas de prueba
    Sale::query()->create([
        'branch_id' => $superadmin->branch_id,
        'user_id' => $superadmin->id,
        'receipt_number' => 'VTA-PURGE-001',
        'document_type' => 'sale_note',
        'customer_name' => 'Cliente Purga',
        'sold_at' => now(),
        'subtotal' => 100,
        'discount_total' => 0,
        'advance_amount' => 0,
        'balance_due' => 100,
        'total' => 100,
        'status' => 'issued',
    ]);
    Sale::query()->create([
        'branch_id' => $superadmin->branch_id,
        'user_id' => $superadmin->id,
        'receipt_number' => 'VTA-PURGE-002',
        'document_type' => 'sale_note',
        'customer_name' => 'Cliente Purga 2',
        'sold_at' => now(),
        'subtotal' => 200,
        'discount_total' => 0,
        'advance_amount' => 0,
        'balance_due' => 200,
        'total' => 200,
        'status' => 'issued',
    ]);

    expect(Sale::query()->count())->toBe(2);

    $this->actingAs($superadmin)
        ->post(route('system-superadmin.database-purge.execute'), [
            'scope' => 'selected',
            'tables' => ['sales'],
            'only_soft_deleted' => false,
            'create_backup' => false,
            'preview_token' => app(DatabasePurgeService::class)->preview(['scope' => 'selected', 'tables' => ['sales'], 'only_soft_deleted' => false, 'create_backup' => false], $superadmin)['preview_token'],
            'confirmation' => 'BORRAR',
        ])
        ->assertRedirect(route('system-superadmin.database-purge.index'))
        ->assertSessionHas('success');

    // Hard delete permanente: debe ser 0 registros incluso con withTrashed
    expect(Sale::withTrashed()->count())->toBe(0);
});

it('ejecuta purga unicamente de registros en papelera cuando only_soft_deleted es true', function () {
    $superadmin = createSuperadminUser();

    $activeSale = Sale::query()->create([
        'branch_id' => $superadmin->branch_id,
        'user_id' => $superadmin->id,
        'receipt_number' => 'VTA-ACTIVA',
        'document_type' => 'sale_note',
        'customer_name' => 'Activo',
        'sold_at' => now(),
        'subtotal' => 100,
        'total' => 100,
        'status' => 'issued',
    ]);

    $deletedSale = Sale::query()->create([
        'branch_id' => $superadmin->branch_id,
        'user_id' => $superadmin->id,
        'receipt_number' => 'VTA-ELIMINADA',
        'document_type' => 'sale_note',
        'customer_name' => 'Eliminado',
        'sold_at' => now(),
        'subtotal' => 50,
        'total' => 50,
        'status' => 'voided',
    ]);
    $deletedSale->delete(); // Soft delete

    expect(Sale::query()->count())->toBe(1)
        ->and(Sale::withTrashed()->count())->toBe(2);

    $this->actingAs($superadmin)
        ->post(route('system-superadmin.database-purge.execute'), [
            'scope' => 'selected',
            'tables' => ['sales'],
            'only_soft_deleted' => true,
            'create_backup' => false,
            'preview_token' => app(DatabasePurgeService::class)->preview(['scope' => 'selected', 'tables' => ['sales'], 'only_soft_deleted' => true, 'create_backup' => false], $superadmin)['preview_token'],
            'confirmation' => 'BORRAR',
        ])
        ->assertRedirect(route('system-superadmin.database-purge.index'));

    // Registro activo se conserva, el soft-deleted desaparece definitivamente
    expect(Sale::query()->count())->toBe(1)
        ->and(Sale::withTrashed()->count())->toBe(1)
        ->and(Sale::query()->first()->receipt_number)->toBe('VTA-ACTIVA');
});

it('borra toda la base de datos operativa preservando usuarios roles y sucursales', function () {
    $superadmin = createSuperadminUser();

    $product = Product::query()->create([
        'name' => 'Producto Operativo',
        'sku' => 'OP-001',
        'barcode' => 'PR-OP-001',
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);

    ProductBranchStock::query()->create([
        'branch_id' => $superadmin->branch_id,
        'product_id' => $product->id,
        'available_meters' => 50,
        'reserved_meters' => 0,
    ]);

    Purchase::query()->create([
        'branch_id' => $superadmin->branch_id,
        'user_id' => $superadmin->id,
        'document_number' => 'COMP-OP-001',
        'purchase_date' => now()->toDateString(),
        'total_amount' => 200,
        'status' => 'received',
    ]);

    Sale::query()->create([
        'branch_id' => $superadmin->branch_id,
        'user_id' => $superadmin->id,
        'receipt_number' => 'VTA-OP-001',
        'document_type' => 'sale_note',
        'customer_name' => 'Cliente Op',
        'sold_at' => now(),
        'subtotal' => 150,
        'total' => 150,
        'status' => 'issued',
    ]);

    $this->actingAs($superadmin)
        ->post(route('system-superadmin.database-purge.execute'), [
            'scope' => 'all_operational',
            'only_soft_deleted' => false,
            'create_backup' => false,
            'preview_token' => app(DatabasePurgeService::class)->preview(['scope' => 'all_operational', 'only_soft_deleted' => false, 'create_backup' => false], $superadmin)['preview_token'],
            'confirmation' => 'BORRAR OPERATIVO',
        ])
        ->assertRedirect(route('system-superadmin.database-purge.index'));

    // Compras, ventas, stocks vaciados
    expect(Sale::withTrashed()->count())->toBe(0)
        ->and(Purchase::withTrashed()->count())->toBe(0)
        ->and(ProductBranchStock::query()->count())->toBe(0);

    // Usuarios, sucursales y roles deben permanecer intactos
    expect(User::query()->where('id', $superadmin->id)->exists())->toBeTrue()
        ->and(Branch::query()->where('id', $superadmin->branch_id)->exists())->toBeTrue()
        ->and($superadmin->hasRole(SystemRoles::SYSTEM_SUPERADMIN))->toBeTrue();
});

it('bloquea la purga si la palabra de confirmacion es incorrecta', function () {
    $superadmin = createSuperadminUser();

    $this->actingAs($superadmin)
        ->post(route('system-superadmin.database-purge.execute'), [
            'scope' => 'all_operational',
            'only_soft_deleted' => false,
            'create_backup' => false,
            'confirmation' => 'PALABRA_INCORRECTA',
        ])
        ->assertSessionHasErrors('confirmation');
});
