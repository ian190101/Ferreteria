<?php

use App\Support\SystemRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    private array $permissions = [
        'ai-assistant.view',
        'ai-assistant.chat',
        'ai-assistant.manage',
        'ai-assistant.channels.manage',
        'ai-assistant.credentials.manage',
        'ai-assistant.external-api.manage',
        'ai-assistant.reports.generate',
        'ai-assistant.orders.create',
        'ai-assistant.actions.approve',
        'ai-assistant.sensitive-data.view',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('ai_assistant_channels')) {
            Schema::create('ai_assistant_channels', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
                $table->string('provider', 40);
                $table->string('name', 120);
                $table->string('status', 30)->default('inactive')->index();
                $table->string('webhook_url')->nullable();
                $table->text('encrypted_credentials')->nullable();
                $table->json('settings')->nullable();
                $table->json('allowed_scopes')->nullable();
                $table->timestamp('last_verified_at')->nullable();
                $table->timestamps();
                $table->unique(['provider', 'name']);
            });
        }

        if (! Schema::hasTable('ai_assistant_conversations')) {
            Schema::create('ai_assistant_conversations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('ai_assistant_channel_id')->nullable()->constrained()->nullOnDelete();
                $table->string('subject_type', 40)->default('internal')->index();
                $table->string('external_user_ref')->nullable()->index();
                $table->string('status', 30)->default('open')->index();
                $table->string('last_intent')->nullable();
                $table->json('context')->nullable();
                $table->timestamp('last_message_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_assistant_messages')) {
            Schema::create('ai_assistant_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ai_assistant_conversation_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('role', 30);
                $table->string('message_type', 30)->default('text');
                $table->longText('content')->nullable();
                $table->string('audio_path')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['ai_assistant_conversation_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('ai_assistant_tool_runs')) {
            Schema::create('ai_assistant_tool_runs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ai_assistant_conversation_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('ai_assistant_message_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('tool', 80)->index();
                $table->string('status', 30)->default('pending')->index();
                $table->json('input')->nullable();
                $table->json('output')->nullable();
                $table->text('error')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_assistant_api_clients')) {
            Schema::create('ai_assistant_api_clients', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('name', 120);
                $table->string('token_hash', 128)->unique();
                $table->json('scopes');
                $table->string('status', 30)->default('active')->index();
                $table->unsignedInteger('rate_limit_per_minute')->default(30);
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_assistant_knowledge_sources')) {
            Schema::create('ai_assistant_knowledge_sources', function (Blueprint $table) {
                $table->id();
                $table->string('source_type', 60)->index();
                $table->string('source_key')->index();
                $table->string('title', 180);
                $table->longText('content');
                $table->json('metadata')->nullable();
                $table->timestamp('indexed_at')->nullable();
                $table->timestamps();
                $table->unique(['source_type', 'source_key']);
            });
        }

        foreach ($this->permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::query()
            ->whereIn('name', ['superadmin', SystemRoles::SYSTEM_SUPERADMIN])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($this->permissions));
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_assistant_knowledge_sources');
        Schema::dropIfExists('ai_assistant_api_clients');
        Schema::dropIfExists('ai_assistant_tool_runs');
        Schema::dropIfExists('ai_assistant_messages');
        Schema::dropIfExists('ai_assistant_conversations');
        Schema::dropIfExists('ai_assistant_channels');

        $permissionIds = DB::table('permissions')->whereIn('name', $this->permissions)->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
