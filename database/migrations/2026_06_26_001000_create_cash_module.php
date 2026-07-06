<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_register_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('opened_at')->index();
            $table->dateTime('closed_at')->nullable()->index();
            $table->decimal('opening_amount', 18, 2)->default(0);
            $table->decimal('cash_income_amount', 18, 2)->default(0);
            $table->decimal('cash_expense_amount', 18, 2)->default(0);
            $table->decimal('expected_cash_amount', 18, 2)->default(0);
            $table->decimal('counted_cash_amount', 18, 2)->nullable();
            $table->decimal('difference_amount', 18, 2)->default(0);
            $table->string('status', 24)->default('open')->index();
            $table->text('opening_notes')->nullable();
            $table->text('closing_notes')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status', 'opened_at']);
            $table->index(['opened_by', 'opened_at']);
            $table->index(['closed_by', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_register_sessions');
    }
};
