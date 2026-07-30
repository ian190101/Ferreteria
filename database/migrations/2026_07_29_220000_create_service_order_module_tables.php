<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('worker_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_number', 80)->unique();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('title', 180);
            $table->string('service_type', 80)->nullable()->index();
            $table->string('status', 40)->default('pending')->index();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->decimal('labor_amount', 18, 4)->default(0);
            $table->decimal('materials_amount', 18, 4)->default(0);
            $table->decimal('advance_amount', 18, 4)->default(0);
            $table->decimal('total_amount', 18, 4)->default(0);
            $table->text('diagnosis')->nullable();
            $table->text('work_performed')->nullable();
            $table->text('warranty_terms')->nullable();
            $table->json('evidence')->nullable();
            $table->json('signature')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status', 'scheduled_at']);
            $table->index(['worker_id', 'status']);
            $table->index(['customer_id', 'status']);
        });

        Schema::create('service_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 18, 3);
            $table->string('unit_label', 40)->nullable();
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->boolean('discount_inventory')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['service_order_id', 'product_id']);
        });

        if (Schema::hasTable('permissions')) {
            foreach (['service-orders.view', 'service-orders.manage'] as $permission) {
                DB::table('permissions')->updateOrInsert(
                    ['name' => $permission, 'guard_name' => 'web'],
                    ['created_at' => now(), 'updated_at' => now()],
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('service_order_items');
        Schema::dropIfExists('service_orders');
    }
};
