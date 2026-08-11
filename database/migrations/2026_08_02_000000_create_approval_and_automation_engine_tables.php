<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_flow_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 120)->unique();
            $table->string('name', 180);
            $table->string('entity_type', 80)->index();
            $table->string('action', 120)->index();
            $table->string('trigger', 120)->index();
            $table->json('conditions')->nullable();
            $table->json('approver_roles')->nullable();
            $table->json('approver_permissions')->nullable();
            $table->unsignedTinyInteger('min_approvals')->default(1);
            $table->boolean('requires_reason')->default(true);
            $table->boolean('blocks_until_approved')->default(true);
            $table->unsignedInteger('expires_after_minutes')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_flow_rule_id')->nullable()->constrained('approval_flow_rules')->nullOnDelete();
            $table->string('entity_type', 80)->index();
            $table->unsignedBigInteger('entity_id')->index();
            $table->string('action', 120)->index();
            $table->string('status', 40)->default('pending')->index();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->text('reason')->nullable();
            $table->text('resolution_note')->nullable();
            $table->json('payload')->nullable();
            $table->json('approvals')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id', 'action', 'status'], 'approval_requests_lookup_idx');
        });

        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 120)->unique();
            $table->string('name', 180);
            $table->string('entity_type', 80)->index();
            $table->string('trigger', 120)->index();
            $table->json('conditions')->nullable();
            $table->json('actions')->nullable();
            $table->unsignedInteger('cooldown_minutes')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_rule_id')->constrained('automation_rules')->cascadeOnDelete();
            $table->string('entity_type', 80)->index();
            $table->unsignedBigInteger('entity_id')->nullable()->index();
            $table->string('trigger', 120)->index();
            $table->string('status', 40)->default('completed')->index();
            $table->json('context')->nullable();
            $table->json('actions')->nullable();
            $table->text('result_message')->nullable();
            $table->timestamp('executed_at')->index();
            $table->timestamps();

            $table->index(['automation_rule_id', 'executed_at'], 'automation_runs_rule_executed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_rules');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_flow_rules');
    }
};
