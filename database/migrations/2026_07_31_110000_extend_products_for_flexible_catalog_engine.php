<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('catalog_settings')->nullable()->after('attributes');
            $table->boolean('requires_lot')->default(false)->after('catalog_settings')->index();
            $table->boolean('requires_expiration_date')->default(false)->after('requires_lot')->index();
            $table->boolean('is_rentable')->default(false)->after('requires_expiration_date')->index();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'catalog_settings',
                'requires_lot',
                'requires_expiration_date',
                'is_rentable',
            ]);
        });
    }
};
