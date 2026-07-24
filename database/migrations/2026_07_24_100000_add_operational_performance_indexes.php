<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_branch_stocks', function (Blueprint $table) {
            $table->index(['branch_id', 'is_enabled', 'available_meters'], 'pbs_branch_enabled_available_idx');
            $table->index(['product_id', 'is_enabled', 'available_meters'], 'pbs_product_enabled_available_idx');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->index(['is_active', 'product_category_id', 'name'], 'products_active_category_name_idx');
            $table->index(['is_active', 'barcode'], 'products_active_barcode_idx');
        });

        Schema::table('product_coils', function (Blueprint $table) {
            $table->index(['branch_id', 'status', 'available_meters'], 'coils_branch_status_available_idx');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->index(['branch_id', 'status', 'sold_at'], 'sales_branch_status_sold_idx');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->index(['branch_id', 'status', 'purchase_date'], 'purchases_branch_status_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', fn (Blueprint $table) => $table->dropIndex('purchases_branch_status_date_idx'));
        Schema::table('sales', fn (Blueprint $table) => $table->dropIndex('sales_branch_status_sold_idx'));
        Schema::table('product_coils', fn (Blueprint $table) => $table->dropIndex('coils_branch_status_available_idx'));
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_active_category_name_idx');
            $table->dropIndex('products_active_barcode_idx');
        });
        Schema::table('product_branch_stocks', function (Blueprint $table) {
            $table->dropIndex('pbs_branch_enabled_available_idx');
            $table->dropIndex('pbs_product_enabled_available_idx');
        });
    }
};
