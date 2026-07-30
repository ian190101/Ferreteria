<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 120);
            $table->string('symbol', 20)->nullable();
            $table->decimal('exchange_rate_to_base', 18, 8)->default(1);
            $table->unsignedTinyInteger('rounding_decimals')->default(2);
            $table->boolean('is_base')->default(false)->index();
            $table->boolean('cash_enabled')->default(true)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_currencies');
    }
};
