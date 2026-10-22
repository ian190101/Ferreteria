<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Banks\Models\BankAccount;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Purchases\Models\Purchase;
use App\Modules\Purchases\Models\Supplier;
use App\Modules\Sales\Models\DocumentSequence;
use App\Modules\Settings\Services\RecoveryJournal;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use App\Support\SystemCacheInvalidator;
use App\Support\SystemRoles;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

// Este ensayo crea datos ficticios y solo admite la instalación aislada acordada.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): never {
    $message = $exception::class === RuntimeException::class ? $exception->getMessage() : 'Revisa el registro privado del entorno de ensayo.';
    fwrite(STDERR, $exception::class.': '.$message.PHP_EOL);
    exit(1);
});
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--solo-preproduccion'
    || DB::connection()->getDatabaseName() !== 'calmina_preproduccion'
    || config('database.connections.mysql.username') !== 'calmina_app'
    || ! User::query()->where('email', 'qa-preproduccion@example.test')->exists()) {
    throw new RuntimeException('El ensayo está restringido a la base y usuario de preproducción.');
}
$mode = $argv[2] ?? 'flow';
if (! in_array($mode, ['flow', 'maintenance'], true)) {
    throw new RuntimeException('Modo de ensayo desconocido.');
}
function confirmQa(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException('Falló la comprobación: '.$message);
    }
    echo json_encode(['verified' => $message, 'at' => gmdate('c')], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
}
$suffix = strtoupper(bin2hex(random_bytes(4)));
$branch = Branch::query()->firstOrCreate(['code' => 'QA-HTTP'], ['name' => 'Ensayo HTTP ficticio', 'barcode' => 'QA-HTTP', 'is_active' => true]);
$password = bin2hex(random_bytes(24));
$user = User::query()->updateOrCreate(['email' => 'qa-http@example.test'], [
    'name' => 'Ensayo HTTP', 'password' => Hash::make($password), 'branch_id' => $branch->id,
    'email_verified_at' => now(), 'is_active' => true, 'force_password_change' => false,
]);
$user->assignRole(Role::findOrCreate(SystemRoles::SYSTEM_SUPERADMIN, 'web'));
$user->accessibleBranches()->syncWithoutDetaching([$branch->id]);
$jar = new CookieJar;
$client = new Client(['base_uri' => 'http://127.0.0.1:10000', 'cookies' => $jar, 'http_errors' => false, 'allow_redirects' => false, 'timeout' => 55]);
$request = function (string $method, string $path, array $data = [], array $expected = [200]) use ($client, $jar): array {
    $headers = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest', 'Referer' => 'http://127.0.0.1:10000/'];
    foreach ($jar->toArray() as $cookie) {
        if ($cookie['Name'] === 'XSRF-TOKEN') {
            $headers['X-XSRF-TOKEN'] = rawurldecode($cookie['Value']);
        }
    }
    $options = ['headers' => $headers];
    if ($data !== []) {
        $options[$method === 'GET' ? 'query' : 'json'] = $data;
    }
    $response = $client->request($method, $path, $options);
    $body = json_decode((string) $response->getBody(), true) ?? [];
    if (! in_array($response->getStatusCode(), $expected, true)) {
        throw new RuntimeException($method.' '.$path.' respondió '.$response->getStatusCode().' '.json_encode($body['errors'] ?? $body['message'] ?? []));
    }

    return $body;
};
$request('GET', '/login');
$request('POST', '/login', ['email' => $user->email, 'password' => $password], [302]);
unset($password);
$status = $request('GET', '/system-superadmin/git-versioning/status');
confirmQa(($status['mode'] ?? '') === 'image' && filled($status['release']['commit'] ?? null), 'El panel identifica la imagen y su revisión desplegada');

if ($mode === 'flow') {
    BusinessProfile::query()->update(['status' => 'archived']);
    BusinessProfile::query()->create(['name' => 'Ensayo HTTP '.$suffix, 'business_type' => 'mixed', 'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([]), 'applied_at' => now()]);
    SystemCacheInvalidator::bumpOperational();
    $product = Product::query()->create(['name' => 'Bobina ficticia '.$suffix, 'sku' => 'QA-'.$suffix, 'barcode' => 'QA-PR-'.$suffix,
        'inventory_tracking_mode' => Product::TRACKING_COIL, 'minimum_stock_meters' => 0, 'is_active' => true]);
    $account = BankAccount::query()->firstOrCreate(['branch_id' => $branch->id, 'name' => 'Banco ficticio QA'], [
        'bank_name' => 'Banco ficticio', 'account_number' => 'QA-NO-REAL', 'currency_code' => 'BOB',
        'opening_balance' => 1000, 'current_balance' => 1000, 'is_active' => true]);
    $balance = (float) $account->current_balance;
    $method = PaymentMethod::query()->firstOrCreate(['code' => 'transfer'], ['name' => 'Transferencia ficticia', 'requires_reference' => true, 'is_active' => true]);
    $number = 'QA-HTTP-'.$suffix;
    $request('POST', '/purchases', ['branch_id' => $branch->id, 'document_number' => $number, 'purchase_date' => date('Y-m-d'), 'status' => 'received',
        'items' => [['product_id' => $product->id, 'meters' => 100, 'unit_cost' => 10, 'lot_number' => $number, 'coil_barcode' => 'QA-COIL-'.$suffix]]], [302]);
    $purchase = Purchase::query()->where('document_number', $number)->firstOrFail();
    $coil = ProductCoil::query()->where('product_id', $product->id)->firstOrFail();
    confirmQa((float) $coil->available_meters === 100.0 && (float) $purchase->total_amount === 1000.0, 'Compra HTTP recibida y bobina trazable con 100 metros');
    foreach ([100, 200] as $amount) {
        $request('POST', '/payments/purchase-payments', ['purchase_id' => $purchase->id, 'payment_method_id' => $method->id, 'amount' => $amount, 'reference' => $number], [302]);
    }
    confirmQa((float) $purchase->fresh()->balance_due === 700.0 && (float) $account->fresh()->current_balance === $balance - 300, 'Dos pagos HTTP actualizan saldo y banco');
    $request('POST', '/inventory/reservations', ['branch_id' => $branch->id, 'product_id' => $product->id, 'product_coil_id' => $coil->id, 'meters' => 10, 'reason' => 'Ensayo ficticio'], [302]);
    $reservation = InventoryReservation::query()->where('product_coil_id', $coil->id)->firstOrFail();
    $blocked = $request('PATCH', '/purchases/'.$purchase->id.'/void', ['reason' => 'Ensayo bloqueado por reserva'], [422]);
    confirmQa(! empty($blocked['errors']) && $purchase->fresh()->status === 'received' && (float) $account->fresh()->current_balance === $balance - 300 && (float) $coil->fresh()->available_meters === 100.0,
        'La reserva bloquea la anulación HTTP sin modificar compra, banco ni bobina');
    $request('PATCH', '/inventory/reservations/'.$reservation->id.'/release', [], [302]);
    confirmQa($reservation->fresh()->status === 'released', 'La reserva se libera mediante su flujo HTTP');
    $request('GET', '/purchases/'.$purchase->id.'/void-preview');
    $request('PATCH', '/purchases/'.$purchase->id.'/void', ['reason' => 'Ensayo integral aprobado'], [302]);
    confirmQa($purchase->fresh()->status === 'voided' && (float) $account->fresh()->current_balance === $balance
        && ! ProductCoil::query()->whereKey($coil->id)->exists() && $purchase->payments()->count() === 0,
        'La anulación HTTP revierte ambos pagos, banco e inventario y conserva auditoría');
    $request('PATCH', '/purchases/'.$purchase->id.'/void', ['reason' => 'Reintento'], [422]);
    confirmQa((float) $account->fresh()->current_balance === $balance && filled($purchase->fresh()->voided_by), 'El reintento no duplica reversos');
} else {
    confirmQa((bool) config('maintenance.purge_enabled'), 'La purga fue habilitada después de aprobar su matriz');
    $supplier = Supplier::query()->create(['name' => 'Proveedor ficticio recuperable '.$suffix, 'is_active' => true]);
    $sequence = DocumentSequence::query()->firstOrCreate(['branch_id' => $branch->id, 'document_type' => 'sale_note'], [
        'name' => 'Numeración ficticia QA', 'prefix' => 'QA-NV-', 'next_number' => 17, 'padding' => 6, 'is_active' => true]);
    $next = $sequence->next_number;
    $options = ['scope' => 'selected', 'tables' => ['suppliers'], 'create_backup' => false];
    $preview = $request('GET', '/system-superadmin/database-purge/preview', $options);
    confirmQa(($preview['can_execute'] ?? false) && ($preview['backup']['required'] ?? false), 'La vista previa exige respaldo aunque el cliente lo desmarque');
    $request('POST', '/system-superadmin/database-purge/execute', [...$options, 'confirmation' => 'BORRAR', 'preview_token' => $preview['preview_token']], [302]);
    $run = DB::table('maintenance_purge_runs')->latest('created_at')->first();
    $deadline = time() + 1800;
    do {
        sleep(3);
        $runs = $request('GET', '/system-superadmin/database-purge/runs')['runs'];
        $result = collect($runs)->firstWhere('id', $run->id);
    } while (in_array($result['status'] ?? '', ['pending', 'running', 'committed'], true) && time() < $deadline);
    confirmQa(($result['status'] ?? '') === 'completed', 'La cola completa la purga con respaldo y restauración temporal verificados');
    confirmQa(! Supplier::withTrashed()->whereKey($supplier->id)->exists() && $sequence->fresh()->next_number === $next, 'La purga de proveedores conserva la numeración de ventas');
    $backupId = $result['result']['backup_id'];
    $before = $request('GET', '/settings/system/recovery-runs')['runs'];
    $known = array_column($before, 'id');
    $request('POST', '/settings/system/backups/'.$backupId.'/restore', ['confirmation' => 'RESTAURAR'], [302]);
    $deadline = time() + 1800;
    do {
        sleep(3);
        // Durante el DDL la autenticación puede no leer sus tablas; el diario sigue en disco.
        $runs = $request('GET', '/settings/system/recovery-runs', [], [200, 500, 503])['runs'] ?? [];
        $recovery = collect($runs)->first(fn ($item) => ! in_array($item['id'], $known, true));
        if ($recovery === null) {
            $recovery = collect(app(RecoveryJournal::class)->recent())
                ->first(fn ($item) => ! in_array($item['id'], $known, true));
        }
    } while (($recovery === null || in_array($recovery['status'] ?? '', ['pending', 'running'], true)) && time() < $deadline);
    confirmQa(($recovery['status'] ?? '') === 'completed', 'La restauración HTTP en cola finaliza y su progreso sobrevive a reemplazar la base');
    DB::purge();
    confirmQa(Supplier::query()->whereKey($supplier->id)->exists() && $sequence->fresh()->next_number === $next
        && (int) DB::selectOne('SELECT @@FOREIGN_KEY_CHECKS AS enabled')->enabled === 1,
        'Se recuperaron los datos ficticios y correlativos con integridad activa');
}
echo json_encode(['ok' => true, 'mode' => $mode, 'revision' => $status['release']['commit']], JSON_THROW_ON_ERROR).PHP_EOL;
