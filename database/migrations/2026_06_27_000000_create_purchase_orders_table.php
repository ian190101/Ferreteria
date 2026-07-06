<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('converted_purchase_id')->nullable()->constrained('purchases')->nullOnDelete();
            $table->string('order_number', 80)->unique();
            $table->date('ordered_at')->index();
            $table->date('expected_at')->nullable()->index();
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->string('status', 24)->default('draft')->index();
            $table->text('notes')->nullable();
            $table->timestamp('approved_at')->nullable()->index();
            $table->timestamp('converted_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status', 'ordered_at']);
            $table->index(['supplier_id', 'ordered_at']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('coil_barcode', 80)->nullable()->index();
            $table->decimal('kilograms', 18, 3)->nullable();
            $table->decimal('meters', 18, 3);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('conversion_factor', 18, 6)->nullable();
            $table->string('lot_number', 80)->nullable()->index();
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['purchase_order_id', 'product_id']);
            $table->index(['product_id', 'lot_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
