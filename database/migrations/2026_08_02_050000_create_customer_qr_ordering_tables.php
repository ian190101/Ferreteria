<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_qr_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('token', 80)->unique();
            $table->string('context_type')->default('general');
            $table->string('context_reference')->nullable();
            $table->string('service_mode')->default('pickup');
            $table->json('allowed_order_types')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('requires_customer_name')->default(true);
            $table->boolean('requires_customer_phone')->default(false);
            $table->boolean('requires_table_or_reference')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['branch_id', 'is_active']);
            $table->index(['context_type', 'context_reference']);
        });

        Schema::create('customer_qr_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_qr_channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('public_code', 32)->unique();
            $table->string('status')->default('pending');
            $table->string('order_type')->default('pickup');
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('table_or_reference')->nullable();
            $table->text('notes')->nullable();
            $table->json('items');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('service_fee', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->foreignId('accepted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rejected_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('converted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('converted_to_type')->nullable();
            $table->unsignedBigInteger('converted_to_id')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('internal_notes')->nullable();
            $table->string('client_ip_hash', 128)->nullable();
            $table->string('user_agent_hash', 128)->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status', 'submitted_at']);
            $table->index(['customer_qr_channel_id', 'status']);
            $table->index(['converted_to_type', 'converted_to_id']);
        });

        Schema::table('inventory_reservations', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_qr_order_id')->nullable()->after('sale_id');
            $table->index('customer_qr_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_reservations', function (Blueprint $table) {
            $table->dropIndex(['customer_qr_order_id']);
            $table->dropColumn('customer_qr_order_id');
        });

        Schema::dropIfExists('customer_qr_orders');
        Schema::dropIfExists('customer_qr_channels');
    }
};
