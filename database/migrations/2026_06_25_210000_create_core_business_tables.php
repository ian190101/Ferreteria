<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 32)->unique();
            $table->string('barcode', 80)->unique();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('branch_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('primary_color', 16)->default('#2563eb');
            $table->string('secondary_color', 16)->default('#0f172a');
            $table->string('logo_path')->nullable();
            $table->string('theme_mode', 16)->default('system')->index();
            $table->json('options')->nullable();
            $table->timestamps();

            $table->unique('branch_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('email')->index();
            $table->timestamp('last_login_at')->nullable()->after('password');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');

            $table->index(['branch_id', 'is_active']);
        });

        Schema::create('thicknesses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('millimeters', 8, 4);
            $table->decimal('kg_to_meter_factor', 18, 6);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('millimeters');
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thickness_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('sku', 80)->unique();
            $table->string('barcode', 80)->unique();
            $table->string('inventory_tracking_mode', 16)->default('global')->index();
            $table->decimal('default_width', 12, 4)->nullable();
            $table->decimal('minimum_stock_meters', 18, 3)->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['thickness_id', 'is_active']);
        });

        Schema::create('product_branch_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('available_meters', 18, 3)->default(0);
            $table->decimal('reserved_meters', 18, 3)->default(0);
            $table->timestamps();

            $table->unique(['branch_id', 'product_id']);
            $table->index(['product_id', 'branch_id']);
        });

        Schema::create('product_coils', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('barcode', 80)->unique();
            $table->string('lot_number', 80)->index();
            $table->decimal('initial_meters', 18, 3);
            $table->decimal('available_meters', 18, 3);
            $table->decimal('initial_kg', 18, 3)->nullable();
            $table->string('status', 24)->default('available')->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'product_id', 'status']);
            $table->index(['product_id', 'lot_number']);
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('tax_id', 64)->nullable()->index();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('product_coils');
        Schema::dropIfExists('product_branch_stocks');
        Schema::dropIfExists('products');
        Schema::dropIfExists('thicknesses');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'is_active']);
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['is_active', 'last_login_at', 'last_login_ip']);
        });

        Schema::dropIfExists('branch_settings');
        Schema::dropIfExists('branches');
    }
};
