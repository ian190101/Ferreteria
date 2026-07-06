<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_return_id')->nullable()->constrained()->nullOnDelete();
            $table->string('credit_number', 80)->unique();
            $table->dateTime('issued_at')->index();
            $table->decimal('amount', 18, 2);
            $table->decimal('exchange_rate_to_bob', 18, 6)->default(1);
            $table->decimal('amount_bob', 18, 2);
            $table->string('reason')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['sale_id', 'issued_at']);
            $table->index(['branch_id', 'issued_at']);
            $table->index(['sale_return_id', 'issued_at']);
            $table->index(['user_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
    }
};
