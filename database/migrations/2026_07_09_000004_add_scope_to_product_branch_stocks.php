<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_branch_stocks', function (Blueprint $table) {
            if (! Schema::hasColumn('product_branch_stocks', 'is_enabled')) {
                $table->boolean('is_enabled')->default(true)->after('reserved_meters')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_branch_stocks', function (Blueprint $table) {
            if (Schema::hasColumn('product_branch_stocks', 'is_enabled')) {
                $table->dropColumn('is_enabled');
            }
        });
    }
};
