<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_state_definitions', function (Blueprint $table) {
            $table->string('workflow_code', 120)->nullable()->after('entity_type')->index();
            $table->json('entry_validations')->nullable()->after('required_permission');
            $table->json('exit_actions')->nullable()->after('actions');
            $table->string('state_type', 40)->default('intermediate')->after('color')->index();
        });

        Schema::create('workflow_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 80)->index();
            $table->string('code', 120);
            $table->string('name', 180);
            $table->text('description')->nullable();
            $table->string('initial_state_code', 120)->nullable();
            $table->json('final_state_codes')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['entity_type', 'code'], 'workflow_definitions_entity_code_unique');
            $table->index(['entity_type', 'is_active', 'is_default'], 'workflow_definitions_default_idx');
        });

        Schema::create('workflow_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_definition_id')->constrained('workflow_definitions')->cascadeOnDelete();
            $table->string('from_state_code', 120);
            $table->string('to_state_code', 120);
            $table->string('label', 160)->nullable();
            $table->string('required_permission', 160)->nullable();
            $table->json('conditions')->nullable();
            $table->json('validations')->nullable();
            $table->json('actions')->nullable();
            $table->boolean('requires_reason')->default(false);
            $table->boolean('is_reversible')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['workflow_definition_id', 'from_state_code', 'to_state_code'], 'workflow_transitions_unique');
            $table->index(['workflow_definition_id', 'from_state_code', 'is_active'], 'workflow_transitions_from_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_transitions');
        Schema::dropIfExists('workflow_definitions');

        Schema::table('business_state_definitions', function (Blueprint $table) {
            $table->dropColumn([
                'workflow_code',
                'entry_validations',
                'exit_actions',
                'state_type',
            ]);
        });
    }
};
