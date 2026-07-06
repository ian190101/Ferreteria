<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('input_product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('input_product_coil_id')->nullable()->constrained('product_coils')->nullOnDelete();
            $table->foreignId('output_product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('output_product_coil_id')->nullable()->constrained('product_coils')->nullOnDelete();
            $table->string('order_number', 80)->unique();
            $table->dateTime('produced_at')->index();
            $table->decimal('input_meters', 18, 3);
            $table->decimal('output_meters', 18, 3);
            $table->decimal('waste_meters', 18, 3)->default(0);
            $table->string('output_coil_barcode', 80)->nullable()->index();
            $table->string('output_lot_number', 80)->nullable()->index();
            $table->string('status', 24)->default('completed')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status', 'produced_at']);
            $table->index(['input_product_id', 'produced_at']);
            $table->index(['output_product_id', 'produced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_orders');
    }
};
