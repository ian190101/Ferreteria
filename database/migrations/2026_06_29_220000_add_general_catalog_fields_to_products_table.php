<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('category', 80)->default('Calaminas')->after('name')->index();
            $table->string('base_unit', 24)->default('m')->after('inventory_tracking_mode')->index();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropIndex(['base_unit']);
            $table->dropColumn(['category', 'base_unit']);
        });
    }
};
