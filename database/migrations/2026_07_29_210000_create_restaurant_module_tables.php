<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('area_name')->nullable()->index();
            $table->string('name');
            $table->string('code')->nullable();
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->string('status')->default('available')->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'code']);
            $table->index(['branch_id', 'status', 'is_active']);
        });

        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('yield_quantity', 12, 3)->default(1);
            $table->text('instructions')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'product_id']);
            $table->index(['product_id', 'is_active']);
        });

        Schema::create('recipe_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->string('unit_label')->nullable();
            $table->decimal('waste_percentage', 8, 3)->default(0);
            $table->timestamps();

            $table->unique(['recipe_id', 'ingredient_product_id']);
            $table->index('ingredient_product_id');
        });

        Schema::create('kitchen_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_table_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->foreignId('waiter_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('order_number')->unique();
            $table->string('customer_label')->nullable();
            $table->string('channel')->default('table')->index();
            $table->string('preparation_area')->nullable()->index();
            $table->string('status')->default('sent')->index();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status', 'sent_at']);
            $table->index(['restaurant_table_id', 'status']);
        });

        Schema::create('kitchen_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kitchen_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->string('unit_label')->nullable();
            $table->decimal('unit_price', 14, 4)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->string('status')->default('sent')->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status']);
        });

        if (Schema::hasTable('permissions')) {
            foreach (['restaurant.view', 'restaurant.manage'] as $permission) {
                DB::table('permissions')->updateOrInsert(
                    ['name' => $permission, 'guard_name' => 'web'],
                    ['created_at' => now(), 'updated_at' => now()],
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_order_items');
        Schema::dropIfExists('kitchen_orders');
        Schema::dropIfExists('recipe_items');
        Schema::dropIfExists('recipes');
        Schema::dropIfExists('restaurant_tables');
    }
};
