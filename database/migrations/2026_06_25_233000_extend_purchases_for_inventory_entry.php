<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->string('coil_barcode', 80)->nullable()->after('product_coil_id');
            $table->string('description')->nullable()->after('lot_number');

            $table->index('coil_barcode');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropIndex(['coil_barcode']);
            $table->dropColumn(['coil_barcode', 'description']);
        });
    }
};
