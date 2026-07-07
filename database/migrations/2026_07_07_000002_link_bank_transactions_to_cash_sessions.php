<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_register_sessions', function (Blueprint $table) {
            $table->decimal('bank_income_amount', 18, 2)->default(0)->after('cash_expense_amount');
            $table->decimal('bank_expense_amount', 18, 2)->default(0)->after('bank_income_amount');
            $table->decimal('bank_net_amount', 18, 2)->default(0)->after('bank_expense_amount');
        });

        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->foreignId('cash_register_session_id')
                ->nullable()
                ->after('user_id')
                ->constrained('cash_register_sessions')
                ->nullOnDelete();
            $table->index(['cash_register_session_id', 'status', 'transacted_at'], 'bank_transactions_cash_session_status_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropIndex('bank_transactions_cash_session_status_date_index');
            $table->dropConstrainedForeignId('cash_register_session_id');
        });

        Schema::table('cash_register_sessions', function (Blueprint $table) {
            $table->dropColumn(['bank_income_amount', 'bank_expense_amount', 'bank_net_amount']);
        });
    }
};
