<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->decimal('display_quantity', 18, 3)->default(1)->after('coil_barcode');
            $table->string('display_unit_label', 24)->nullable()->after('display_quantity');
            $table->json('item_attributes')->nullable()->after('display_unit_label');
            $table->string('calculation_mode', 24)->default('direct')->after('item_attributes')->index();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropIndex(['calculation_mode']);
            $table->dropColumn(['display_quantity', 'display_unit_label', 'item_attributes', 'calculation_mode']);
        });
    }
};
