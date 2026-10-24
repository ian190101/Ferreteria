<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'purchase_items', 'purchase_order_receipt_items', 'sale_items',
        'sale_return_items', 'delivery_note_items', 'inventory_movements',
        'inventory_reservations', 'inventory_adjustments',
    ];

    private const SNAPSHOTS = [
        'purchase_order_receipt_items', 'sale_items', 'sale_return_items',
        'delivery_note_items', 'inventory_movements',
    ];

    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->foreignId('product_coil_id')->nullable()->index()
                ->constrained('product_coils')->restrictOnDelete();
        });

        foreach (self::SNAPSHOTS as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('lot_code_snapshot', 80)->nullable();
            });
        }

        foreach (self::TABLES as $name) {
            $this->replaceForeignKey($name, 'RESTRICT');
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            $this->replaceForeignKey($name, 'SET NULL');
        }

        foreach (self::SNAPSHOTS as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('lot_code_snapshot');
            });
        }

        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->dropForeign(['product_coil_id']);
            $table->dropIndex(['product_coil_id']);
            $table->dropColumn('product_coil_id');
        });
    }

    private function replaceForeignKey(string $name, string $onDelete): void
    {
        $constraint = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $name)
            ->where('COLUMN_NAME', 'product_coil_id')
            ->where('REFERENCED_TABLE_NAME', 'product_coils')
            ->value('CONSTRAINT_NAME');

        if ($constraint === null) {
            throw new RuntimeException("Falta la FK {$name}.product_coil_id.");
        }

        Schema::table($name, function (Blueprint $table) use ($constraint, $onDelete): void {
            $table->dropForeign($constraint);
            $table->foreign('product_coil_id')->references('id')
                ->on('product_coils')->onDelete($onDelete);
        });
    }
};
