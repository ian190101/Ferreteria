<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('display_quantity', 18, 3)->default(1)->after('unit_label');
            $table->string('display_unit_label', 24)->nullable()->after('display_quantity');
            $table->json('item_attributes')->nullable()->after('display_unit_label');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['display_quantity', 'display_unit_label', 'item_attributes']);
        });
    }
};
