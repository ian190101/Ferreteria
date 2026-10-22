<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Purchases\Services\PurchaseService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\OperationalScenario;

function grantPurchaseVoidPermissions(User $user, array $permissions): void
{
    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
}

function purchaseVoidSnapshot(): array
{
    $snapshot = [];
    foreach (['purchases', 'purchase_payments', 'product_coils', 'product_branch_stocks', 'inventory_movements', 'bank_transactions', 'purchase_orders', 'purchase_order_items'] as $table) {
        $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }

    return $snapshot;
}

it('devuelve 403 sin modificar registros ante peticiones directas sin ambos permisos', function (string $entry, array $permissions) {
    $scenario = OperationalScenario::create();
    grantPurchaseVoidPermissions($scenario['user'], $permissions);
    $purchase = $scenario['purchases'][0];
    $payment = $purchase->payments()->firstOrFail();
    $before = purchaseVoidSnapshot();
    $url = $entry === 'purchase' ? route('purchases.void', $purchase) : route('payments.purchase-payments.void', $payment);

    $this->actingAs($scenario['user'])->patchJson($url, ['reason' => 'Solicitud directa'])->assertForbidden();

    expect(purchaseVoidSnapshot())->toBe($before);
})->with([
    'compra sin permisos' => ['purchase', []],
    'compra solo compras' => ['purchase', ['purchases.manage']],
    'compra solo pagos' => ['purchase', ['payments.manage']],
    'pago sin permisos' => ['payment', []],
    'pago solo compras' => ['payment', ['purchases.manage']],
    'pago solo pagos' => ['payment', ['payments.manage']],
]);

it('bloquea ambas entradas y la vista previa al intentar acceder a otra sucursal', function () {
    $scenario = OperationalScenario::create();
    $branch = Branch::query()->create(['name' => 'Otra sucursal', 'code' => 'QA-OTRA', 'barcode' => 'QA-OTRA', 'is_active' => true]);
    $user = User::factory()->create(['branch_id' => $branch->id, 'email_verified_at' => now()]);
    grantPurchaseVoidPermissions($user, ['purchases.manage', 'payments.manage']);
    $purchase = $scenario['purchases'][0];
    $before = purchaseVoidSnapshot();

    $this->actingAs($user)->patchJson(route('purchases.void', $purchase), ['reason' => 'Otra sucursal'])->assertForbidden();
    $this->patchJson(route('payments.purchase-payments.void', $purchase->payments()->firstOrFail()), ['reason' => 'Otra sucursal'])->assertForbidden();
    $this->getJson(route('purchases.void-preview', $purchase))->assertForbidden();

    expect(purchaseVoidSnapshot())->toBe($before);
});

it('permite anular compras sin pagos con permiso de compras y conserva motivo fecha usuario y referencias', function () {
    $scenario = OperationalScenario::create();
    $purchase = $scenario['purchases'][1];
    $purchase->update(['status' => 'draft']);
    grantPurchaseVoidPermissions($scenario['user'], ['purchases.manage']);

    $this->actingAs($scenario['user'])->patchJson(route('purchases.void', $purchase), ['reason' => 'Cancelación autorizada'])->assertRedirect();

    $purchase->refresh();
    expect($purchase->status)->toBe('voided')
        ->and($purchase->voided_by)->toBe($scenario['user']->id)
        ->and($purchase->voided_at)->not->toBeNull()
        ->and($purchase->void_reason)->toContain('Cancelación autorizada')
        ->and($purchase->void_references['items'])->toBe($purchase->items()->pluck('id')->all());
});

it('permite la anulación integral con ambos permisos desde cada entrada', function (string $entry) {
    $scenario = OperationalScenario::create();
    $purchase = $scenario['purchases'][0];
    $purchase->update(['status' => 'draft']);
    grantPurchaseVoidPermissions($scenario['user'], ['purchases.manage', 'payments.manage']);
    $paymentIds = $purchase->payments()->pluck('id')->all();
    $url = $entry === 'purchase' ? route('purchases.void', $purchase) : route('payments.purchase-payments.void', $paymentIds[0]);

    $this->actingAs($scenario['user'])->patchJson($url, ['reason' => 'Cancelación integral autorizada'])->assertRedirect();

    expect($purchase->fresh()->status)->toBe('voided')
        ->and($purchase->payments()->count())->toBe(0)
        ->and($purchase->fresh()->void_references['payments'])->toBe($paymentIds);
})->with(['purchase', 'payment']);

it('muestra el alcance autorizado antes de confirmar sin cambiar datos', function () {
    $scenario = OperationalScenario::create();
    grantPurchaseVoidPermissions($scenario['user'], ['purchases.manage', 'payments.manage']);
    $before = purchaseVoidSnapshot();

    $this->actingAs($scenario['user'])->getJson(route('purchases.void-preview', $scenario['purchases'][0]))
        ->assertOk()->assertJsonPath('document_number', 'QA-COMP-001')
        ->assertJsonCount(2, 'payments')->assertJsonCount(2, 'inventory');

    expect(purchaseVoidSnapshot())->toBe($before);
});

it('revalida permisos en el servicio aunque se invoque sin controlador', function () {
    $scenario = OperationalScenario::create();
    grantPurchaseVoidPermissions($scenario['user'], ['purchases.manage']);
    $before = purchaseVoidSnapshot();

    try {
        app(PurchaseService::class)->voidPurchase($scenario['purchases'][0], $scenario['user']->id, 'Sin permiso financiero');
        $this->fail('La autorización debió rechazar la operación.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }

    expect(purchaseVoidSnapshot())->toBe($before);
});

it('expone a la interfaz la autorización real para compras con pagos', function (bool $financialPermission) {
    $scenario = OperationalScenario::create();
    $permissions = ['purchases.view', 'purchases.manage', 'payments.view'];
    if ($financialPermission) {
        $permissions[] = 'payments.manage';
    }
    grantPurchaseVoidPermissions($scenario['user'], $permissions);

    $this->actingAs($scenario['user'])->get(route('purchases.show', $scenario['purchases'][0]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('purchase.can_void', $financialPermission));
    $this->get(route('payments.purchase-payments.index'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('payments.data.0.can_void', $financialPermission));
})->with([true, false]);
