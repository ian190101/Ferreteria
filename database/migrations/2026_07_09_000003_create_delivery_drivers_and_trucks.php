<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('document_number', 80)->nullable()->index();
            $table->string('phone', 40)->nullable();
            $table->string('license_number', 80)->nullable()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'is_active']);
        });

        Schema::create('delivery_trucks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('plate', 40)->index();
            $table->string('description')->nullable();
            $table->string('brand', 80)->nullable();
            $table->string('model', 80)->nullable();
            $table->decimal('capacity', 18, 3)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'is_active']);
        });

        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->foreignId('delivery_driver_id')->nullable()->after('user_id')->constrained('delivery_drivers')->nullOnDelete();
            $table->foreignId('delivery_truck_id')->nullable()->after('delivery_driver_id')->constrained('delivery_trucks')->nullOnDelete();
            $table->boolean('manual_driver')->default(false)->after('delivery_truck_id');
            $table->boolean('manual_truck')->default(false)->after('manual_driver');
        });

        Schema::table('delivery_note_items', function (Blueprint $table) {
            $table->decimal('display_quantity', 18, 3)->default(0)->after('meters');
            $table->string('display_unit_label', 24)->nullable()->after('display_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_note_items', function (Blueprint $table) {
            $table->dropColumn(['display_quantity', 'display_unit_label']);
        });

        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_driver_id');
            $table->dropConstrainedForeignId('delivery_truck_id');
            $table->dropColumn(['manual_driver', 'manual_truck']);
        });

        Schema::dropIfExists('delivery_trucks');
        Schema::dropIfExists('delivery_drivers');
    }
};
