<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->decimal('paid_amount', 18, 2)->default(0)->index();
            $table->decimal('balance_due', 18, 2)->default(0)->index();
            $table->string('payment_status', 24)->default('unpaid')->index();
        });

        Schema::create('purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->dateTime('paid_at')->index();
            $table->decimal('amount', 18, 2);
            $table->string('reference', 120)->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['purchase_id', 'paid_at']);
            $table->index(['branch_id', 'paid_at']);
            $table->index(['payment_method_id', 'paid_at']);
            $table->index(['user_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_payments');

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn(['paid_amount', 'balance_due', 'payment_status']);
        });
    }
};
