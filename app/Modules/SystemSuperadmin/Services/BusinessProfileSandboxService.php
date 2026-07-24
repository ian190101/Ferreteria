<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Modules\Cash\Models\CashRegisterSession;
use App\Modules\Customers\Models\Customer;
use App\Modules\Banks\Models\BankAccount;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Payments\Models\SalePayment;
use App\Modules\Purchases\Models\Purchase;
use App\Modules\Purchases\Models\Supplier;
use App\Modules\Sales\Models\Sale;
use App\Modules\SystemSuperadmin\Models\BusinessProfileSandboxSession;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BusinessProfileSandboxService
{
    public function sessionFor(int $userId): BusinessProfileSandboxSession
    {
        $session = BusinessProfileSandboxSession::query()
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->latest('last_activity_at')
            ->first();

        if ($session) {
            return $session;
        }

        return BusinessProfileSandboxSession::query()->create([
            'user_id' => $userId,
            'name' => 'Demo sandbox',
            'payload' => $this->snapshot(),
            'status' => 'active',
            'expires_at' => now()->addHours(8),
            'last_activity_at' => now(),
        ]);
    }

    public function replacePayload(BusinessProfileSandboxSession $session, array $payload): BusinessProfileSandboxSession
    {
        $payload['generatedAt'] ??= now()->toIso8601String();
        $payload['sandboxPersistedAt'] = now()->toIso8601String();

        $session->update([
            'payload' => $payload,
            'last_activity_at' => now(),
        ]);

        return $session->refresh();
    }

    public function reset(BusinessProfileSandboxSession $session): BusinessProfileSandboxSession
    {
        $session->update([
            'payload' => $this->snapshot(),
            'last_activity_at' => now(),
        ]);

        return $session->refresh();
    }

    public function provisionDatabase(BusinessProfileSandboxSession $session): BusinessProfileSandboxSession
    {
        if (filled($session->database_name) && $this->databaseExists((string) $session->database_name)) {
            return $session;
        }

        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        if ($driver !== 'mysql') {
            throw new \RuntimeException('La demo completa requiere MySQL o MariaDB para clonar una base aislada.');
        }

        $sourceDatabase = (string) config("database.connections.{$connection}.database");
        $targetDatabase = 'sandbox_'.$session->id.'_'.Str::lower(Str::random(8));

        DB::statement('CREATE DATABASE '.$this->quoteIdentifier($targetDatabase).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        foreach ($this->cloneableTables($sourceDatabase) as $table) {
            $source = $this->quoteIdentifier($sourceDatabase).'.'.$this->quoteIdentifier($table);
            $target = $this->quoteIdentifier($targetDatabase).'.'.$this->quoteIdentifier($table);
            DB::statement("CREATE TABLE {$target} LIKE {$source}");
            DB::statement("INSERT INTO {$target} SELECT * FROM {$source}");
        }

        $session->update([
            'database_name' => $targetDatabase,
            'last_activity_at' => now(),
        ]);

        return $session->refresh();
    }

    public function discardDatabase(BusinessProfileSandboxSession $session): void
    {
        if (filled($session->database_name) && $this->databaseExists((string) $session->database_name)) {
            DB::statement('DROP DATABASE '.$this->quoteIdentifier((string) $session->database_name));
        }

        $session->update([
            'status' => 'discarded',
            'last_activity_at' => now(),
        ]);
    }

    public function activateConnection(BusinessProfileSandboxSession $session): void
    {
        if (! filled($session->database_name)) {
            return;
        }

        $connection = config('database.default');
        Config::set("database.connections.{$connection}.database", $session->database_name);
        DB::purge($connection);
        DB::reconnect($connection);
    }

    public function snapshot(): array
    {
        $branches = Branch::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'code', 'point_of_sale_name'])
            ->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
                'point_of_sale' => $branch->point_of_sale_name,
            ])
            ->values();

        $products = ProductBranchStock::query()
            ->with(['product:id,name,sku,barcode,base_unit,sale_price', 'branch:id,name'])
            ->where('is_enabled', true)
            ->orderByDesc('available_meters')
            ->limit(8)
            ->get()
            ->map(fn (ProductBranchStock $stock) => [
                'name' => $stock->product?->name ?? 'Producto',
                'sku' => $stock->product?->sku,
                'barcode' => $stock->product?->barcode,
                'branch' => $stock->branch?->name,
                'unit' => $stock->product?->base_unit ?: 'unidad',
                'stock' => round((float) $stock->available_meters, 3),
                'price' => round((float) ($stock->product?->sale_price ?? 0), 2),
                'status' => (float) $stock->available_meters > 0 ? 'Activo' : 'Sin stock',
            ])
            ->values();

        $customers = Customer::query()
            ->where('is_active', true)
            ->latest('id')
            ->limit(8)
            ->get(['id', 'name', 'document_number', 'phone'])
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->name,
                'document' => $customer->document_number,
                'phone' => $customer->phone,
            ])
            ->values();

        $suppliers = Supplier::query()
            ->where('is_active', true)
            ->latest('id')
            ->limit(8)
            ->get(['id', 'name', 'tax_id', 'phone'])
            ->map(fn (Supplier $supplier) => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'tax_id' => $supplier->tax_id,
                'phone' => $supplier->phone,
            ])
            ->values();

        $bankAccounts = BankAccount::query()
            ->with('branch:id,name')
            ->where('is_active', true)
            ->latest('id')
            ->limit(8)
            ->get(['id', 'branch_id', 'name', 'bank_name', 'account_number', 'current_balance'])
            ->map(fn (BankAccount $account) => [
                'id' => $account->id,
                'name' => $account->name,
                'bank' => $account->bank_name,
                'account' => $account->account_number,
                'branch' => $account->branch?->name,
                'balance' => round((float) $account->current_balance, 2),
            ])
            ->values();

        $sales = Sale::query()
            ->with('branch:id,name')
            ->latest('sold_at')
            ->limit(5)
            ->get(['id', 'branch_id', 'receipt_number', 'document_type', 'customer_name', 'total', 'balance_due', 'status', 'sold_at'])
            ->map(fn (Sale $sale) => [
                'receipt_number' => $sale->receipt_number,
                'document_type' => $sale->document_type,
                'customer' => $sale->customer_name ?: 'Cliente ocasional',
                'branch' => $sale->branch?->name,
                'total' => round((float) $sale->total, 2),
                'balance_due' => round((float) $sale->balance_due, 2),
                'status' => $sale->status,
            ])
            ->values();

        return [
            'generatedAt' => now()->toIso8601String(),
            'branches' => $branches,
            'products' => $products,
            'customers' => $customers,
            'suppliers' => $suppliers,
            'bankAccounts' => $bankAccounts,
            'sales' => $sales,
            'totals' => [
                'stock' => round((float) ProductBranchStock::query()->where('is_enabled', true)->sum('available_meters'), 3),
                'sales' => round((float) Sale::query()->where('document_type', 'sale_note')->sum('total'), 2),
                'receivables' => round((float) Sale::query()->where('document_type', 'sale_note')->sum('balance_due'), 2),
                'purchases' => round((float) Purchase::query()->sum('total_amount'), 2),
                'cashOpen' => CashRegisterSession::query()->where('status', CashRegisterSession::STATUS_OPEN)->count(),
                'payments' => round((float) SalePayment::query()->sum('amount'), 2),
            ],
        ];
    }

    private function cloneableTables(string $database): array
    {
        return collect(DB::select('SHOW FULL TABLES FROM '.$this->quoteIdentifier($database).' WHERE Table_type = "BASE TABLE"'))
            ->map(fn ($row) => (array) $row)
            ->map(fn (array $row) => (string) array_values($row)[0])
            ->reject(fn (string $table) => in_array($table, ['cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions'], true))
            ->values()
            ->all();
    }

    private function databaseExists(string $database): bool
    {
        return collect(DB::select('SHOW DATABASES LIKE '.$this->quoteLiteral($database)))->isNotEmpty();
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private function quoteLiteral(string $value): string
    {
        return "'".str_replace(["\\", "'"], ["\\\\", "\\'"], $value)."'";
    }
}
