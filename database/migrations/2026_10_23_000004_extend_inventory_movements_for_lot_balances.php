<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->decimal('lot_quantity_before', 18, 3)->nullable();
            $table->decimal('lot_quantity_after', 18, 3)->nullable();
            $table->foreignId('reverses_movement_id')->nullable()->index()
                ->constrained('inventory_movements')->restrictOnDelete();
            $table->string('idempotency_key', 191)->nullable()->unique('movements_idempotency_key_uq');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropForeign(['reverses_movement_id']);
            $table->dropIndex(['reverses_movement_id']);
            $table->dropUnique('movements_idempotency_key_uq');
            $table->dropColumn(['lot_quantity_before', 'lot_quantity_after', 'reverses_movement_id', 'idempotency_key']);
        });
    }
};
