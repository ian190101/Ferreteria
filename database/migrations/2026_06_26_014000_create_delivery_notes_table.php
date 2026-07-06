<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('delivery_number', 80)->unique();
            $table->dateTime('delivered_at')->index();
            $table->decimal('total_meters', 18, 3)->default(0);
            $table->string('recipient_name')->nullable();
            $table->string('recipient_document', 80)->nullable()->index();
            $table->string('recipient_phone', 40)->nullable();
            $table->string('driver_name')->nullable();
            $table->string('vehicle_plate', 40)->nullable()->index();
            $table->string('status', 24)->default('partial')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['sale_id', 'delivered_at']);
            $table->index(['branch_id', 'status', 'delivered_at']);
            $table->index(['user_id', 'delivered_at']);
        });

        Schema::create('delivery_note_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_note_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_coil_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('meters', 18, 3);
            $table->timestamps();

            $table->index(['delivery_note_id', 'sale_item_id']);
            $table->index(['sale_item_id', 'product_id']);
            $table->index(['product_id', 'product_coil_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_note_items');
        Schema::dropIfExists('delivery_notes');
    }
};
