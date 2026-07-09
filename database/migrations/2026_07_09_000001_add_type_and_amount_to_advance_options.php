<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advance_options', function (Blueprint $table) {
            if (! Schema::hasColumn('advance_options', 'type')) {
                $table->string('type', 24)->default('percentage')->after('name')->index();
            }

            if (! Schema::hasColumn('advance_options', 'amount')) {
                $table->decimal('amount', 18, 2)->nullable()->after('percentage');
            }
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE advance_options MODIFY percentage DECIMAL(5, 2) NULL');
        }
        DB::table('advance_options')->whereNull('type')->update(['type' => 'percentage']);
    }

    public function down(): void
    {
        DB::table('advance_options')
            ->whereNull('percentage')
            ->update(['percentage' => 0]);

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE advance_options MODIFY percentage DECIMAL(5, 2) NOT NULL');
        }

        Schema::table('advance_options', function (Blueprint $table) {
            if (Schema::hasColumn('advance_options', 'amount')) {
                $table->dropColumn('amount');
            }

            if (Schema::hasColumn('advance_options', 'type')) {
                $table->dropColumn('type');
            }
        });
    }
};
