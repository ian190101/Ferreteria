<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('to_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_coil_id')->nullable()->constrained('product_coils')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('transfer_number', 80)->unique();
            $table->string('tracking_mode', 16)->index();
            $table->decimal('meters', 18, 3);
            $table->string('status', 32)->default('completed')->index();
            $table->dateTime('transferred_at')->index();
            $table->string('reason');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['from_branch_id', 'to_branch_id', 'status', 'transferred_at'], 'idx_inventory_transfers_flow');
            $table->index(['product_id', 'transferred_at']);
            $table->index(['product_coil_id', 'transferred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfers');
    }
};
