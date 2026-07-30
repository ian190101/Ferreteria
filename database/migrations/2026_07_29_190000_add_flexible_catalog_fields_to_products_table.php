<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('item_type', 40)->default('physical')->after('base_unit')->index();
            $table->boolean('is_sellable')->default(true)->after('item_type')->index();
            $table->boolean('is_purchasable')->default(true)->after('is_sellable')->index();
            $table->boolean('is_inventory_item')->default(true)->after('is_purchasable')->index();
            $table->boolean('is_consumable')->default(false)->after('is_inventory_item')->index();
            $table->boolean('is_prepared')->default(false)->after('is_consumable')->index();
            $table->boolean('is_digital')->default(false)->after('is_prepared')->index();
            $table->unsignedInteger('duration_minutes')->nullable()->after('is_digital');
            $table->unsignedInteger('preparation_minutes')->nullable()->after('duration_minutes');

            $table->index(['item_type', 'is_active'], 'products_item_type_active_idx');
            $table->index(['is_sellable', 'is_active'], 'products_sellable_active_idx');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_item_type_active_idx');
            $table->dropIndex('products_sellable_active_idx');
            $table->dropColumn([
                'item_type',
                'is_sellable',
                'is_purchasable',
                'is_inventory_item',
                'is_consumable',
                'is_prepared',
                'is_digital',
                'duration_minutes',
                'preparation_minutes',
            ]);
        });
    }
};
