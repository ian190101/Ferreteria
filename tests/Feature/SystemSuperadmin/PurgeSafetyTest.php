<?php

declare(strict_types=1);

use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Customers\Models\Customer;
use App\Modules\Payments\Models\SalePayment;
use App\Modules\Sales\Models\Sale;
use App\Modules\SystemSuperadmin\Services\DatabasePurgeService;
use App\Support\SystemRoles;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\Support\OperationalScenario;

function purgeSafetyScenario(): array
{
    $scenario = OperationalScenario::create();
    $scenario['user']->assignRole(Role::findOrCreate(SystemRoles::SYSTEM_SUPERADMIN, 'web'));
    $sale = Sale::query()->create([
        'branch_id' => $scenario['branch']->id, 'user_id' => $scenario['user']->id,
        'receipt_number' => 'QA-NV-000001', 'document_type' => 'sale_note', 'sold_at' => now(),
        'subtotal' => 100, 'total' => 100, 'balance_due' => 80, 'status' => 'issued',
    ]);
    $item = $sale->items()->create(['product_id' => $scenario['products']['global']->id, 'meters' => 10, 'unit_price' => 10, 'total' => 100]);
    $payment = SalePayment::query()->create([
        'sale_id' => $sale->id, 'branch_id' => $scenario['branch']->id, 'user_id' => $scenario['user']->id,
        'payment_method_id' => $scenario['purchases'][0]->payments()->value('payment_method_id'),
        'paid_at' => now(), 'amount' => 20, 'amount_bob' => 20,
    ]);

    return [...$scenario, 'sale' => $sale, 'saleItem' => $item, 'salePayment' => $payment];
}

function purgeSafetyOptions(array $scenario, array $tables, array $extra = []): array
{
    $options = [...['scope' => 'selected', 'tables' => $tables, 'create_backup' => false], ...$extra];

    return [...$options, 'preview_token' => app(DatabasePurgeService::class)->preview($options, $scenario['user'])['preview_token']];
}

function purgeSafetySnapshot(): array
{
    return [...OperationalScenario::snapshot(),
        'sales' => DB::table('sales')->orderBy('id')->get()->toJson(),
        'sale_items' => DB::table('sale_items')->orderBy('id')->get()->toJson(),
        'sale_payments' => DB::table('sale_payments')->orderBy('id')->get()->toJson()];
}

it('bloquea seleccionar solo sales cuando permanecen items o pagos fuera del alcance', function () {
    $scenario = purgeSafetyScenario();
    $options = purgeSafetyOptions($scenario, ['sales']);
    $preview = app(DatabasePurgeService::class)->preview($options, $scenario['user']);
    $before = purgeSafetySnapshot();

    expect($preview['can_execute'])->toBeFalse()
        ->and(implode(' ', $preview['blockers']))->toContain('sale_items', 'sale_payments');
    $this->actingAs($scenario['user'])->postJson(route('system-superadmin.database-purge.execute'), [...$options, 'confirmation' => 'BORRAR'])
        ->assertUnprocessable()->assertJsonValidationErrors('tables');

    expect(purgeSafetySnapshot())->toBe($before);
});

it('elimina hijos antes de padres sin desactivar integridad y conserva la auditoria', function () {
    $scenario = purgeSafetyScenario();
    DB::table('audits')->insert(['event' => 'created', 'auditable_type' => Sale::class, 'auditable_id' => $scenario['sale']->id, 'old_values' => '{}', 'new_values' => '{"receipt":"QA-NV-000001"}']);
    $options = purgeSafetyOptions($scenario, ['sales', 'sale_items', 'sale_payments']);
    $this->actingAs($scenario['user'])->postJson(route('system-superadmin.database-purge.execute'), [...$options, 'confirmation' => 'BORRAR'])->assertRedirect();

    expect(DB::table('sales')->count())->toBe(0)
        ->and(DB::table('sale_items')->count())->toBe(0)
        ->and(DB::table('sale_payments')->count())->toBe(0)
        ->and((int) DB::selectOne('SELECT @@FOREIGN_KEY_CHECKS AS enabled')->enabled)->toBe(1);
    $this->assertDatabaseHas('audits', ['auditable_id' => $scenario['sale']->id]);
    $this->assertDatabaseHas('maintenance_logs', ['event' => 'database_purged']);
    $this->assertDatabaseHas('purchases', ['id' => $scenario['purchases'][0]->id]);
});

it('impide eliminar desde papelera un padre con hijos activos incluso si fueron seleccionados', function () {
    $scenario = purgeSafetyScenario();
    $scenario['sale']->delete();
    $options = purgeSafetyOptions($scenario, ['sales', 'sale_items', 'sale_payments'], ['only_soft_deleted' => true]);
    $preview = app(DatabasePurgeService::class)->preview($options, $scenario['user']);

    expect($preview['can_execute'])->toBeFalse()
        ->and($preview['counts']['sale_items'])->toBe(0)
        ->and(implode(' ', $preview['blockers']))->toContain('sale_items');
});

it('incluye dependencias de varios niveles y elimina con integridad al activar la opcion', function () {
    $scenario = purgeSafetyScenario();
    $customer = Customer::query()->create(['name' => 'Cliente de purga', 'is_active' => true]);
    $scenario['sale']->update(['customer_id' => $customer->id]);
    Tests\Support\MaintenanceScenario::sale($scenario['user'], 'QA-NV-000002');
    InventoryMovement::query()->create([
        'branch_id' => $scenario['branch']->id, 'product_id' => $scenario['products']['global']->id,
        'user_id' => $scenario['user']->id, 'type' => 'sale_stock_out', 'meters_delta' => -10,
        'meters_before' => 100, 'meters_after' => 90, 'source_type' => Sale::class, 'source_id' => $scenario['sale']->id,
    ]);
    $options = purgeSafetyOptions($scenario, ['customers'], ['include_dependencies' => true]);
    $preview = app(DatabasePurgeService::class)->preview($options, $scenario['user']);

    expect($preview['can_execute'])->toBeTrue()
        ->and($preview['counts']['sales'])->toBe(2)
        ->and($preview['added_dependency_tables'])->toContain('sales', 'sale_items', 'sale_payments', 'inventory_movements')
        ->and(array_intersect($preview['tables'], $preview['protected']))->toBe([]);
    $this->actingAs($scenario['user'])->postJson(route('system-superadmin.database-purge.execute'), [...$options, 'confirmation' => 'BORRAR'])
        ->assertRedirect();

    foreach (['customers', 'sales', 'sale_items', 'sale_payments', 'inventory_movements'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
    $this->assertDatabaseHas('users', ['id' => $scenario['user']->id]);
    $this->assertDatabaseHas('purchases', ['id' => $scenario['purchases'][0]->id]);
    $this->assertDatabaseHas('maintenance_logs', ['event' => 'database_purged']);
    expect((int) DB::selectOne('SELECT @@FOREIGN_KEY_CHECKS AS enabled')->enabled)->toBe(1);
});

it('mantiene la proteccion de hijos activos en papelera con dependencias automaticas', function () {
    $scenario = purgeSafetyScenario();
    $scenario['sale']->delete();
    $before = purgeSafetySnapshot();
    $options = purgeSafetyOptions($scenario, ['sales'], ['include_dependencies' => true, 'only_soft_deleted' => true]);
    $preview = app(DatabasePurgeService::class)->preview($options, $scenario['user']);

    expect($preview['can_execute'])->toBeFalse()
        ->and($preview['added_dependency_tables'])->toContain('sale_items', 'sale_payments')
        ->and($preview['counts']['sale_items'])->toBe(0);
    $this->actingAs($scenario['user'])->postJson(route('system-superadmin.database-purge.execute'), [...$options, 'confirmation' => 'BORRAR'])
        ->assertUnprocessable()->assertJsonValidationErrors('tables');
    expect(purgeSafetySnapshot())->toBe($before);
});

it('exige recalcular la vista previa al cambiar la opcion de dependencias', function () {
    $scenario = purgeSafetyScenario();
    $options = purgeSafetyOptions($scenario, ['sales'], ['include_dependencies' => true]);
    $before = purgeSafetySnapshot();
    $this->actingAs($scenario['user'])->postJson(route('system-superadmin.database-purge.execute'), [
        ...$options, 'include_dependencies' => false, 'confirmation' => 'BORRAR',
    ])->assertUnprocessable()->assertJsonValidationErrors('preview_token');
    expect(purgeSafetySnapshot())->toBe($before);
});

it('detecta referencias polimorficas conservadas y permite ampliar el alcance', function () {
    $scenario = purgeSafetyScenario();
    InventoryMovement::query()->create([
        'branch_id' => $scenario['branch']->id, 'product_id' => $scenario['products']['global']->id,
        'user_id' => $scenario['user']->id, 'type' => 'sale_stock_out', 'meters_delta' => -10,
        'meters_before' => 100, 'meters_after' => 90, 'source_type' => Sale::class, 'source_id' => $scenario['sale']->id,
    ]);
    $options = purgeSafetyOptions($scenario, ['sales', 'sale_items', 'sale_payments']);
    $preview = app(DatabasePurgeService::class)->preview($options, $scenario['user']);
    expect($preview['can_execute'])->toBeFalse()->and(implode(' ', $preview['blockers']))->toContain('polimórficas');

    $options = purgeSafetyOptions($scenario, ['sales', 'sale_items', 'sale_payments', 'inventory_movements']);
    $result = app(DatabasePurgeService::class)->executePurge($options, $scenario['user']);
    expect($result['ok'])->toBeTrue()->and(DB::table('inventory_movements')->count())->toBe(0);
});

it('rechaza tablas protegidas o existentes fuera del catalogo', function (string $table) {
    $scenario = purgeSafetyScenario();
    $this->actingAs($scenario['user'])->getJson(route('system-superadmin.database-purge.preview', ['scope' => 'selected', 'tables' => [$table], 'create_backup' => false]))
        ->assertUnprocessable()->assertJsonValidationErrors('tables');
})->with(['users', 'audits', 'maintenance_logs', 'maintenance_backups', 'maintenance_barriers', 'sessions']);

it('rechaza ejecutar sin vista previa o con otro alcance y detecta datos cambiados', function () {
    $scenario = purgeSafetyScenario();
    $options = purgeSafetyOptions($scenario, ['sales', 'sale_items', 'sale_payments']);
    $this->actingAs($scenario['user'])->postJson(route('system-superadmin.database-purge.execute'), ['scope' => 'selected', 'tables' => ['sales'], 'confirmation' => 'BORRAR'])
        ->assertUnprocessable()->assertJsonValidationErrors('preview_token');
    $this->postJson(route('system-superadmin.database-purge.execute'), [...$options, 'tables' => ['sales'], 'confirmation' => 'BORRAR'])
        ->assertUnprocessable()->assertJsonValidationErrors('preview_token');

    $scenario['sale']->update(['total' => 200]);
    $before = purgeSafetySnapshot();
    $this->postJson(route('system-superadmin.database-purge.execute'), [...$options, 'confirmation' => 'BORRAR'])
        ->assertUnprocessable()->assertJsonValidationErrors('preview_token');
    expect(purgeSafetySnapshot())->toBe($before);
});

it('revierte todas las eliminaciones cuando falla una tabla y conserva el registro del fallo', function () {
    $scenario = purgeSafetyScenario();
    $options = purgeSafetyOptions($scenario, ['sales', 'sale_items', 'sale_payments']);
    $before = purgeSafetySnapshot();
    $triggered = false;
    DB::listen(function (QueryExecuted $query) use (&$triggered): void {
        if (! $triggered && str_contains($query->sql, 'delete from `sale_payments`')) {
            $triggered = true;
            throw new RuntimeException('Fallo de borrado simulado.');
        }
    });
    try {
        app(DatabasePurgeService::class)->executePurge($options, $scenario['user']);
        $this->fail('La purga debió fallar.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Fallo de borrado simulado.');
    }

    expect($triggered)->toBeTrue()->and(purgeSafetySnapshot())->toBe($before);
    $this->assertDatabaseHas('maintenance_logs', ['event' => 'database_purge_failed']);
});

it('coordina las colas y libera su barrera tanto al completar como al fallar', function () {
    $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $job->shouldReceive('payload')->andReturn([]);
    $initialLevel = DB::transactionLevel();
    event(new JobProcessing('sync', $job));
    expect(DB::connection('operational_gate')->transactionLevel())->toBe(1)
        ->and(DB::transactionLevel())->toBe($initialLevel);
    event(new JobProcessed('sync', $job));
    expect(DB::connection('operational_gate')->transactionLevel())->toBe(0);

    event(new JobProcessing('sync', $job));
    event(new JobExceptionOccurred('sync', $job, new RuntimeException('Fallo de trabajo simulado.')));
    expect(DB::connection('operational_gate')->transactionLevel())->toBe(0)
        ->and(DB::transactionLevel())->toBe($initialLevel);
});
