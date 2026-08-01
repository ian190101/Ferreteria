<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dynamic_relationship_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 120);
            $table->string('label', 180);
            $table->string('source_entity_type', 120)->index();
            $table->string('target_entity_type', 120)->index();
            $table->string('type', 40)->default('one_to_many')->index();
            $table->string('inverse_label', 180)->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('allows_multiple')->default(true);
            $table->unsignedInteger('min_items')->nullable();
            $table->unsignedInteger('max_items')->nullable();
            $table->string('cascade_behavior', 40)->default('restrict');
            $table->json('permissions')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['source_entity_type', 'code'], 'dynamic_relationship_definition_source_code_unique');
            $table->index(['source_entity_type', 'target_entity_type', 'is_active'], 'dynamic_relationship_definition_pair_idx');
            $table->index(['is_active', 'sort_order'], 'dynamic_relationship_definition_order_idx');
        });

        Schema::create('dynamic_relationship_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dynamic_relationship_definition_id');
            $table->string('source_entity_type', 120)->index();
            $table->string('source_record_id', 120)->index();
            $table->string('target_entity_type', 120)->index();
            $table->string('target_record_id', 120)->index();
            $table->json('payload')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->foreign('dynamic_relationship_definition_id', 'dyn_rel_value_definition_fk')
                ->references('id')
                ->on('dynamic_relationship_definitions')
                ->cascadeOnDelete();
            $table->unique(
                ['dynamic_relationship_definition_id', 'source_record_id', 'target_record_id'],
                'dynamic_relationship_value_unique'
            );
            $table->index(
                ['dynamic_relationship_definition_id', 'source_entity_type', 'source_record_id', 'is_active'],
                'dynamic_relationship_value_source_idx'
            );
            $table->index(
                ['target_entity_type', 'target_record_id', 'is_active'],
                'dynamic_relationship_value_target_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_relationship_values');
        Schema::dropIfExists('dynamic_relationship_definitions');
    }
};
