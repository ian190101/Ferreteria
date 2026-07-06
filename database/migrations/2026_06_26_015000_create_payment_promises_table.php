<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_promises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('promise_number', 80)->unique();
            $table->date('promised_date')->index();
            $table->decimal('promised_amount', 18, 2);
            $table->string('contact_name')->nullable();
            $table->string('contact_phone', 40)->nullable()->index();
            $table->string('channel', 40)->default('phone')->index();
            $table->string('status', 24)->default('pending')->index();
            $table->text('notes')->nullable();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status', 'promised_date']);
            $table->index(['sale_id', 'status', 'promised_date']);
            $table->index(['user_id', 'promised_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_promises');
    }
};
