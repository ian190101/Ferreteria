<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_commercial_flow_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 120)->unique();
            $table->string('name', 180);
            $table->string('business_type', 80)->nullable()->index();
            $table->string('sales_workflow', 80)->index();
            $table->string('pos_mode', 80)->nullable()->index();
            $table->string('channel', 80)->nullable()->index();
            $table->string('document_type', 80);
            $table->string('customer_mode', 40)->default('optional');
            $table->string('cash_policy', 40)->default('optional');
            $table->string('inventory_timing', 60)->default('sale_note');
            $table->string('payment_policy', 60)->default('single_or_mixed');
            $table->boolean('requires_resource')->default(false);
            $table->boolean('requires_responsible')->default(false);
            $table->boolean('requires_reservation')->default(false);
            $table->boolean('requires_service_order')->default(false);
            $table->boolean('requires_guarantee')->default(false);
            $table->boolean('allows_discount')->default(false);
            $table->boolean('allows_price_override')->default(false);
            $table->boolean('allows_credit')->default(false);
            $table->boolean('allows_advance')->default(false);
            $table->boolean('allows_split_payment')->default(false);
            $table->boolean('allows_mixed_payment')->default(false);
            $table->json('validations')->nullable();
            $table->json('permissions')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['sales_workflow', 'channel', 'is_active'], 'commercial_flow_workflow_channel_idx');
            $table->index(['business_type', 'is_default', 'is_active'], 'commercial_flow_business_default_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_commercial_flow_rules');
    }
};
