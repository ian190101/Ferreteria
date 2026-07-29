<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->index('sales', 'sales_branch_doc_status_sold_idx', ['branch_id', 'document_type', 'status', 'sold_at']);
        $this->index('sales', 'sales_branch_status_sold_idx', ['branch_id', 'status', 'sold_at']);
        $this->index('sale_payments', 'sale_payments_branch_paid_idx', ['branch_id', 'paid_at']);
        $this->index('purchase_payments', 'purchase_payments_branch_paid_idx', ['branch_id', 'paid_at']);
        $this->index('expenses', 'expenses_branch_status_spent_idx', ['branch_id', 'status', 'spent_at']);
        $this->index('purchases', 'purchases_branch_status_date_idx', ['branch_id', 'status', 'purchase_date']);
        $this->index('product_branch_stocks', 'stocks_branch_available_product_idx', ['branch_id', 'available_meters', 'product_id']);
        $this->index('product_branch_stocks', 'stocks_product_branch_enabled_idx', ['product_id', 'branch_id', 'is_enabled']);
        $this->index('product_coils', 'coils_branch_product_status_available_idx', ['branch_id', 'product_id', 'status', 'available_meters']);
        $this->index('cash_register_sessions', 'cash_sessions_branch_status_opened_idx', ['branch_id', 'status', 'opened_at']);
        $this->index('payment_promises', 'payment_promises_branch_status_date_idx', ['branch_id', 'status', 'promised_date']);
        $this->index('inventory_movements', 'inventory_movements_branch_product_created_idx', ['branch_id', 'product_id', 'created_at']);
    }

    public function down(): void
    {
        foreach ([
            'sales' => ['sales_branch_doc_status_sold_idx', 'sales_branch_status_sold_idx'],
            'sale_payments' => ['sale_payments_branch_paid_idx'],
            'purchase_payments' => ['purchase_payments_branch_paid_idx'],
            'expenses' => ['expenses_branch_status_spent_idx'],
            'purchases' => ['purchases_branch_status_date_idx'],
            'product_branch_stocks' => ['stocks_branch_available_product_idx', 'stocks_product_branch_enabled_idx'],
            'product_coils' => ['coils_branch_product_status_available_idx'],
            'cash_register_sessions' => ['cash_sessions_branch_status_opened_idx'],
            'payment_promises' => ['payment_promises_branch_status_date_idx'],
            'inventory_movements' => ['inventory_movements_branch_product_created_idx'],
        ] as $table => $indexes) {
            foreach ($indexes as $index) {
                $this->dropIndex($table, $index);
            }
        }
    }

    private function index(string $table, string $name, array $columns): void
    {
        if (! Schema::hasTable($table) || $this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, fn ($blueprint) => $blueprint->index($columns, $name));
    }

    private function dropIndex(string $table, string $name): void
    {
        if (! Schema::hasTable($table) || ! $this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, fn ($blueprint) => $blueprint->dropIndex($name));
    }

    private function indexExists(string $table, string $name): bool
    {
        $database = DB::getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $name)
            ->exists();
    }
};
