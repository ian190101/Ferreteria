<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('name');
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('document_number', 80)->nullable()->index();
            $table->string('phone', 40)->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_type_id', 'is_active']);
            $table->index(['name', 'is_active']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('currency_id')->constrained()->nullOnDelete();
            $table->index(['customer_id', 'sold_at']);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['customer_id', 'sold_at']);
            $table->dropConstrainedForeignId('customer_id');
        });

        Schema::dropIfExists('customers');
        Schema::dropIfExists('customer_types');
    }
};
