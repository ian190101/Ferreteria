<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_coils', function (Blueprint $table) {
            $table->string('barcode', 80)->nullable()->change();
            $table->string('lot_number', 80)->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('product_coils')
            ->whereNull('barcode')
            ->update(['barcode' => DB::raw("CONCAT('SIN-COD-', id)")]);

        DB::table('product_coils')
            ->whereNull('lot_number')
            ->update(['lot_number' => 'SIN-LOTE']);

        Schema::table('product_coils', function (Blueprint $table) {
            $table->string('barcode', 80)->nullable(false)->change();
            $table->string('lot_number', 80)->nullable(false)->change();
        });
    }
};
