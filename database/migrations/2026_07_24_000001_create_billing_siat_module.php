<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('siat_branch_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('nit', 20);
            $table->string('business_name');
            $table->string('municipality', 80);
            $table->string('phone', 40)->nullable();
            $table->string('system_code', 120);
            $table->unsignedTinyInteger('environment_code')->default(2);
            $table->unsignedTinyInteger('modality_code')->default(2);
            $table->unsignedTinyInteger('emission_type_code')->default(1);
            $table->unsignedTinyInteger('invoice_type_code')->default(1);
            $table->unsignedSmallInteger('document_sector_code')->default(1);
            $table->unsignedInteger('economic_activity_code')->nullable();
            $table->unsignedInteger('sin_product_code')->nullable();
            $table->unsignedInteger('siat_branch_code')->default(0);
            $table->unsignedInteger('point_of_sale_code')->default(0);
            $table->string('token_encrypted', 2048)->nullable();
            $table->string('certificate_path')->nullable();
            $table->string('certificate_password_encrypted')->nullable();
            $table->boolean('is_active')->default(false)->index();
            $table->json('options')->nullable();
            $table->timestamps();

            $table->unique(['branch_id']);
            $table->index(['nit', 'environment_code', 'modality_code']);
        });

        Schema::create('siat_cuis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('code', 120);
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->json('response')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });

        Schema::create('siat_cufd', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('siat_cuis_id')->nullable()->constrained('siat_cuis')->nullOnDelete();
            $table->string('code', 220);
            $table->string('control_code', 120)->nullable();
            $table->text('address')->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->json('response')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'valid_until']);
        });

        Schema::create('siat_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->string('catalog_type', 80);
            $table->string('code', 80);
            $table->string('description');
            $table->json('payload')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['catalog_type', 'code']);
            $table->index(['catalog_type', 'is_active']);
        });

        Schema::create('siat_product_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('economic_activity_code');
            $table->unsignedInteger('sin_product_code');
            $table->unsignedInteger('unit_measure_code');
            $table->string('fiscal_description')->nullable();
            $table->boolean('is_invoiceable')->default(true)->index();
            $table->timestamps();

            $table->unique(['product_id']);
            $table->index(['economic_activity_code', 'sin_product_code'], 'siat_product_map_activity_product_idx');
        });

        Schema::create('siat_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('siat_cufd_id')->nullable()->constrained('siat_cufd')->nullOnDelete();
            $table->string('invoice_number');
            $table->string('cuf', 220)->nullable()->index();
            $table->string('cufd', 220)->nullable();
            $table->unsignedTinyInteger('environment_code')->default(2);
            $table->unsignedTinyInteger('modality_code')->default(2);
            $table->unsignedTinyInteger('emission_type_code')->default(1);
            $table->unsignedTinyInteger('invoice_type_code')->default(1);
            $table->unsignedSmallInteger('document_sector_code')->default(1);
            $table->unsignedInteger('siat_branch_code')->default(0);
            $table->unsignedInteger('point_of_sale_code')->default(0);
            $table->timestamp('issued_at')->nullable()->index();
            $table->string('customer_name')->nullable();
            $table->unsignedInteger('identity_document_type_code')->default(1);
            $table->string('customer_document', 40);
            $table->string('customer_complement', 10)->nullable();
            $table->string('customer_code', 80);
            $table->unsignedInteger('payment_method_code')->default(1);
            $table->string('card_number_masked', 30)->nullable();
            $table->decimal('total_amount', 18, 2);
            $table->decimal('taxable_amount', 18, 2);
            $table->decimal('gift_card_amount', 18, 2)->nullable();
            $table->decimal('additional_discount', 18, 2)->nullable();
            $table->unsignedTinyInteger('exception_code')->default(0);
            $table->string('cafc', 120)->nullable();
            $table->unsignedInteger('currency_code')->default(1);
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->decimal('total_amount_currency', 18, 2);
            $table->string('legend', 500)->nullable();
            $table->string('operator_username', 80);
            $table->longText('xml')->nullable();
            $table->longText('signed_xml')->nullable();
            $table->string('xml_hash', 128)->nullable();
            $table->string('gzip_hash', 128)->nullable();
            $table->longText('gzip_base64')->nullable();
            $table->string('reception_code', 120)->nullable();
            $table->unsignedInteger('siat_state_code')->nullable();
            $table->string('status', 40)->default('draft')->index();
            $table->json('siat_response')->nullable();
            $table->json('observations')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->unsignedInteger('void_reason_code')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'invoice_number']);
            $table->index(['branch_id', 'status', 'issued_at']);
        });

        Schema::create('siat_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siat_invoice_id')->constrained('siat_invoices')->cascadeOnDelete();
            $table->foreignId('sale_item_id')->nullable()->constrained('sale_items')->nullOnDelete();
            $table->unsignedInteger('economic_activity_code');
            $table->unsignedInteger('sin_product_code');
            $table->string('product_code');
            $table->string('description');
            $table->decimal('quantity', 18, 4);
            $table->unsignedInteger('unit_measure_code');
            $table->decimal('unit_price', 18, 4);
            $table->decimal('discount_amount', 18, 2)->nullable();
            $table->decimal('subtotal', 18, 2);
            $table->string('serial_number')->nullable();
            $table->string('imei_number')->nullable();
            $table->timestamps();

            $table->index(['siat_invoice_id']);
        });

        Schema::create('siat_significant_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('siat_cufd_event_id')->nullable()->constrained('siat_cufd')->nullOnDelete();
            $table->foreignId('siat_cufd_send_id')->nullable()->constrained('siat_cufd')->nullOnDelete();
            $table->unsignedInteger('event_code');
            $table->string('reception_code', 120)->nullable();
            $table->text('description')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('status', 40)->default('open')->index();
            $table->json('siat_response')->nullable();
            $table->timestamps();
        });

        Schema::create('siat_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('siat_significant_event_id')->nullable()->constrained('siat_significant_events')->nullOnDelete();
            $table->unsignedInteger('invoice_count')->default(0);
            $table->string('reception_code', 120)->nullable();
            $table->string('hash', 128)->nullable();
            $table->longText('gzip_base64')->nullable();
            $table->string('status', 40)->default('pending')->index();
            $table->json('siat_response')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('siat_package_invoice', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siat_package_id')->constrained('siat_packages')->cascadeOnDelete();
            $table->foreignId('siat_invoice_id')->constrained('siat_invoices')->cascadeOnDelete();
            $table->unsignedInteger('file_number')->nullable();
            $table->timestamps();

            $table->unique(['siat_package_id', 'siat_invoice_id']);
        });

        Schema::create('siat_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('siat_invoice_id')->nullable()->constrained('siat_invoices')->nullOnDelete();
            $table->string('service', 80);
            $table->string('operation', 120);
            $table->string('status', 40)->index();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['service', 'operation', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siat_logs');
        Schema::dropIfExists('siat_package_invoice');
        Schema::dropIfExists('siat_packages');
        Schema::dropIfExists('siat_significant_events');
        Schema::dropIfExists('siat_invoice_items');
        Schema::dropIfExists('siat_invoices');
        Schema::dropIfExists('siat_product_mappings');
        Schema::dropIfExists('siat_catalog_items');
        Schema::dropIfExists('siat_cufd');
        Schema::dropIfExists('siat_cuis');
        Schema::dropIfExists('siat_branch_settings');
    }
};
