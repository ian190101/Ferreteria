<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_reservations', function (Blueprint $table): void {
            $table->foreignId('sale_item_id')->nullable()->constrained('sale_items')->restrictOnDelete();
            $table->foreignId('committed_sale_item_id')->nullable()->constrained('sale_items')->restrictOnDelete();
            $table->decimal('remaining_meters', 18, 3)->nullable();
            $table->index(['product_coil_id', 'status', 'id'], 'reservations_lot_status_id_idx');
            $table->index(['committed_sale_item_id', 'status'], 'reservations_commitment_status_idx');
        });

        DB::table('inventory_reservations')->update([
            'remaining_meters' => DB::raw("CASE WHEN status = 'active' THEN meters ELSE 0 END"),
        ]);

        Schema::table('inventory_reservations', function (Blueprint $table): void {
            $table->decimal('remaining_meters', 18, 3)->default(0)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        // MySQL puede usar el índice compuesto como soporte de la FK preexistente.
        Schema::table('inventory_reservations', function (Blueprint $table): void {
            $table->index('product_coil_id', 'inventory_reservations_lot_fk_idx');
        });
        Schema::table('inventory_reservations', function (Blueprint $table): void {
            $table->dropForeign(['sale_item_id']);
            $table->dropForeign(['committed_sale_item_id']);
            $table->dropIndex('reservations_lot_status_id_idx');
            $table->dropIndex('reservations_commitment_status_idx');
            $table->dropColumn(['sale_item_id', 'committed_sale_item_id', 'remaining_meters']);
        });
    }
};
