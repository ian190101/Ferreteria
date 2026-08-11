<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_integration_connectors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('code', 120)->unique();
            $table->string('name', 180);
            $table->string('provider', 80)->index();
            $table->string('channel', 80)->index();
            $table->string('direction', 40)->default('outbound')->index();
            $table->string('auth_type', 60)->default('none');
            $table->string('base_url', 500)->nullable();
            $table->string('webhook_url', 500)->nullable();
            $table->unsignedInteger('rate_limit_per_minute')->default(60);
            $table->unsignedTinyInteger('timeout_seconds')->default(10);
            $table->json('capabilities')->nullable();
            $table->json('public_config')->nullable();
            $table->text('secret_config')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_status', 40)->nullable()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(['channel', 'provider', 'is_active'], 'business_integrations_channel_provider_idx');
            $table->index(['branch_id', 'channel', 'is_active'], 'business_integrations_branch_channel_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_integration_connectors');
    }
};
