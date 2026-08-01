<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservable_resources', function (Blueprint $table) {
            $table->foreignId('assigned_worker_id')->nullable()->after('branch_id')->constrained('workers')->nullOnDelete();
            $table->string('status', 40)->default('available')->after('capacity')->index();
            $table->string('location', 180)->nullable()->after('status');
            $table->dateTime('maintenance_starts_at')->nullable()->after('location')->index();
            $table->dateTime('maintenance_ends_at')->nullable()->after('maintenance_starts_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('reservable_resources', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_worker_id');
            $table->dropColumn([
                'status',
                'location',
                'maintenance_starts_at',
                'maintenance_ends_at',
            ]);
        });
    }
};
