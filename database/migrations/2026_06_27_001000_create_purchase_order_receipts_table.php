<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->decimal('received_meters', 18, 3)->default(0)->after('meters');
        });

        Schema::create('purchase_order_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('receipt_number', 100)->unique();
            $table->date('received_at')->index();
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['purchase_order_id', 'received_at'], 'por_receipts_order_date_idx');
            $table->index(['branch_id', 'received_at'], 'por_receipts_branch_date_idx');
            $table->index(['supplier_id', 'received_at'], 'por_receipts_supplier_date_idx');
        });

        Schema::create('purchase_order_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_coil_id')->nullable()->constrained()->nullOnDelete();
            $table->string('coil_barcode', 80)->nullable()->index();
            $table->decimal('kilograms', 18, 3)->nullable();
            $table->decimal('meters', 18, 3);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->timestamps();

            $table->index(['purchase_order_receipt_id', 'product_id'], 'por_items_receipt_product_idx');
            $table->index(['purchase_order_item_id', 'product_id'], 'por_items_order_item_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_receipt_items');
        Schema::dropIfExists('purchase_order_receipts');

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('received_meters');
        });
    }
};
