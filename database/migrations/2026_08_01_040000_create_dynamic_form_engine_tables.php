<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dynamic_form_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 120);
            $table->string('code', 120);
            $table->string('name', 180);
            $table->string('flow', 120)->nullable();
            $table->string('workflow_code', 120)->nullable();
            $table->string('state_code', 120)->nullable();
            $table->string('surface', 80)->default('form');
            $table->text('description')->nullable();
            $table->string('submit_label', 120)->nullable();
            $table->json('layout')->nullable();
            $table->json('permissions')->nullable();
            $table->json('validations')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['entity_type', 'code'], 'dyn_form_definition_entity_code_unique');
            $table->index(['entity_type', 'flow', 'is_active'], 'dyn_form_definition_entity_flow_idx');
            $table->index(['workflow_code', 'state_code', 'is_active'], 'dyn_form_definition_workflow_state_idx');
        });

        Schema::create('dynamic_form_field_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dynamic_form_definition_id');
            $table->string('field_code', 120);
            $table->string('label_override', 180)->nullable();
            $table->text('help_text')->nullable();
            $table->string('placeholder', 180)->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_visible')->default(true);
            $table->boolean('is_read_only')->default(false);
            $table->json('default_value')->nullable();
            $table->json('validation_rules')->nullable();
            $table->json('visibility_conditions')->nullable();
            $table->json('required_conditions')->nullable();
            $table->json('options_override')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('dynamic_form_definition_id', 'dyn_form_field_form_fk')
                ->references('id')
                ->on('dynamic_form_definitions')
                ->cascadeOnDelete();
            $table->unique(['dynamic_form_definition_id', 'field_code'], 'dyn_form_field_rule_unique');
            $table->index(['dynamic_form_definition_id', 'is_active', 'sort_order'], 'dyn_form_field_rule_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_form_field_rules');
        Schema::dropIfExists('dynamic_form_definitions');
    }
};
