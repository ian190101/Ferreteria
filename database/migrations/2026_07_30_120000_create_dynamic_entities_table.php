<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dynamic_entities', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('label', 160);
            $table->string('plural_label', 180)->nullable();
            $table->string('base_entity', 80)->nullable()->index();
            $table->string('module', 120)->nullable()->index();
            $table->string('icon', 80)->nullable();
            $table->string('mode', 40)->default('optional')->index();
            $table->boolean('is_visible')->default(true)->index();
            $table->boolean('is_editable')->default(true);
            $table->boolean('is_required')->default(false);
            $table->boolean('is_exportable')->default(false);
            $table->boolean('is_reportable')->default(false);
            $table->boolean('is_auditable')->default(true);
            $table->boolean('is_sensitive')->default(false)->index();
            $table->string('retention_policy', 80)->default('standard');
            $table->json('permissions')->nullable();
            $table->json('settings')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(['is_active', 'is_visible', 'sort_order'], 'dynamic_entities_visible_idx');
            $table->index(['base_entity', 'is_active'], 'dynamic_entities_base_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_entities');
    }
};
