<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('thicknesses', function (Blueprint $table) {
            $table->decimal('kg_per_meter', 18, 6)->nullable()->after('kg_to_meter_factor')->index();
        });

        DB::statement('
            UPDATE thicknesses
            SET kg_per_meter = ROUND(1 / kg_to_meter_factor, 6)
            WHERE kg_to_meter_factor IS NOT NULL
                AND kg_to_meter_factor > 0
                AND kg_per_meter IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('thicknesses', function (Blueprint $table) {
            $table->dropColumn('kg_per_meter');
        });
    }
};
