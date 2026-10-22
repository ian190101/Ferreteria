<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->foreignId('cash_register_session_id')->nullable()->constrained('cash_register_sessions')->restrictOnDelete();
            // Distingue los cobros nuevos sin apertura de los históricos sin asociación registrada.
            $table->boolean('cash_context_recorded')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_register_session_id');
            $table->dropColumn('cash_context_recorded');
        });
    }
};
