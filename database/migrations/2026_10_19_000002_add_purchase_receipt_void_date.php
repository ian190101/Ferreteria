<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_receipts', function (Blueprint $table): void {
            $table->timestamp('voided_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_receipts', function (Blueprint $table): void {
            $table->dropColumn('voided_at');
        });
    }
};
