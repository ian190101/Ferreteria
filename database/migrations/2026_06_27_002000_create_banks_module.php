<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('bank_name', 120);
            $table->string('account_number', 80);
            $table->string('currency_code', 10)->default('BOB');
            $table->decimal('opening_balance', 18, 2)->default(0);
            $table->decimal('current_balance', 18, 2)->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'account_number']);
            $table->index(['branch_id', 'is_active']);
        });

        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40)->index();
            $table->dateTime('transacted_at')->index();
            $table->decimal('amount', 18, 2);
            $table->string('reference', 120)->nullable()->index();
            $table->string('description', 255);
            $table->string('status', 30)->default('registered')->index();
            $table->dateTime('reconciled_at')->nullable()->index();
            $table->dateTime('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status', 'transacted_at']);
            $table->index(['bank_account_id', 'status', 'transacted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('bank_accounts');
    }
};
