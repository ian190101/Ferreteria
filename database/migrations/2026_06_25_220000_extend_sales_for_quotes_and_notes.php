<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 8)->unique();
            $table->string('symbol', 8);
            $table->decimal('exchange_rate_to_bob', 18, 6)->default(1);
            $table->boolean('is_base')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('advance_options', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 24)->default('percentage')->index();
            $table->decimal('percentage', 5, 2)->nullable()->unique();
            $table->decimal('amount', 18, 2)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->string('secondary_phone')->nullable()->after('phone');
            $table->string('point_of_sale_name')->nullable()->after('secondary_phone');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('sale_type_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->foreignId('currency_id')->nullable()->after('sale_type_id')->constrained()->nullOnDelete();
            $table->foreignId('advance_option_id')->nullable()->after('currency_id')->constrained('advance_options')->nullOnDelete();
            $table->string('document_type', 24)->default('sale_note')->after('receipt_number')->index();
            $table->decimal('exchange_rate_to_bob', 18, 6)->default(1)->after('customer_document');
            $table->string('customer_contact', 40)->nullable()->after('customer_document');
            $table->decimal('advance_percentage', 5, 2)->default(0)->after('discount_total');
            $table->decimal('advance_amount', 18, 2)->default(0)->after('advance_percentage');
            $table->decimal('balance_due', 18, 2)->default(0)->after('advance_amount');
            $table->text('terms')->nullable()->after('status');
            $table->text('internal_notes')->nullable()->after('terms');

            $table->index(['document_type', 'status', 'sold_at']);
            $table->index(['sale_type_id', 'sold_at']);
            $table->index(['currency_id', 'sold_at']);
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->string('description')->nullable()->after('product_coil_id');
            $table->string('unit_label', 16)->default('M')->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['description', 'unit_label']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['document_type', 'status', 'sold_at']);
            $table->dropIndex(['sale_type_id', 'sold_at']);
            $table->dropIndex(['currency_id', 'sold_at']);
            $table->dropConstrainedForeignId('sale_type_id');
            $table->dropConstrainedForeignId('currency_id');
            $table->dropConstrainedForeignId('advance_option_id');
            $table->dropColumn([
                'document_type',
                'exchange_rate_to_bob',
                'customer_contact',
                'advance_percentage',
                'advance_amount',
                'balance_due',
                'terms',
                'internal_notes',
            ]);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['secondary_phone', 'point_of_sale_name']);
        });

        Schema::dropIfExists('advance_options');
        Schema::dropIfExists('currencies');
        Schema::dropIfExists('sale_types');
    }
};
