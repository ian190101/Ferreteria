<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('document_number', 80)->index();
            $table->date('purchase_date')->index();
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->string('status', 24)->default('draft')->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status', 'purchase_date']);
            $table->index(['supplier_id', 'purchase_date']);
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_coil_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('kilograms', 18, 3)->nullable();
            $table->decimal('meters', 18, 3);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('conversion_factor', 18, 6)->nullable();
            $table->string('lot_number', 80)->nullable()->index();
            $table->timestamps();

            $table->index(['purchase_id', 'product_id']);
            $table->index(['product_id', 'lot_number']);
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('receipt_number', 80)->unique();
            $table->string('customer_name')->nullable();
            $table->string('customer_document', 80)->nullable()->index();
            $table->dateTime('sold_at')->index();
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->string('status', 24)->default('completed')->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status', 'sold_at']);
            $table->index(['user_id', 'sold_at']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_coil_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('meters', 18, 3);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2);
            $table->timestamps();

            $table->index(['sale_id', 'product_id']);
            $table->index(['product_id', 'product_coil_id']);
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_coil_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->nullableMorphs('source');
            $table->string('type', 24)->index();
            $table->decimal('meters_delta', 18, 3);
            $table->decimal('meters_before', 18, 3);
            $table->decimal('meters_after', 18, 3);
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['branch_id', 'product_id', 'created_at']);
            $table->index(['product_coil_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
    }
};
