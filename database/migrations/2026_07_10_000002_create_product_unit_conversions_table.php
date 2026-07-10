<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_unit_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_unit_id')->constrained()->restrictOnDelete();
            $table->decimal('factor_to_base', 18, 6)->default(1);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['product_id', 'product_unit_id'], 'product_unit_conversions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_unit_conversions');
    }
};
