<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_coil_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('adjustment_number', 80)->unique();
            $table->string('type', 24)->index();
            $table->decimal('meters_delta', 18, 3);
            $table->decimal('meters_before', 18, 3);
            $table->decimal('meters_after', 18, 3);
            $table->string('reason', 255);
            $table->dateTime('adjusted_at')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'type', 'adjusted_at']);
            $table->index(['product_id', 'adjusted_at']);
            $table->index(['product_coil_id', 'adjusted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_adjustments');
    }
};
