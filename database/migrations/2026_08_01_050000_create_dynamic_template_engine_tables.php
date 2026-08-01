<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dynamic_document_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('document_type', 80);
            $table->string('entity_type', 120)->nullable();
            $table->string('code', 120);
            $table->string('name', 180);
            $table->string('paper_type', 40)->default('letter');
            $table->unsignedSmallInteger('thermal_width_mm')->nullable();
            $table->string('printer_area', 80)->nullable();
            $table->unsignedSmallInteger('copies')->default(1);
            $table->json('layout')->nullable();
            $table->json('fields')->nullable();
            $table->json('columns')->nullable();
            $table->text('legal_text')->nullable();
            $table->text('terms_text')->nullable();
            $table->json('permissions')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['document_type', 'code'], 'dyn_doc_templates_type_code_unique');
            $table->index(['branch_id', 'document_type', 'is_active'], 'dyn_doc_templates_branch_type_idx');
            $table->index(['entity_type', 'is_active'], 'dyn_doc_templates_entity_idx');
        });

        Schema::create('dynamic_report_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 120)->unique('dyn_report_templates_code_unique');
            $table->string('name', 180);
            $table->string('module', 80)->nullable();
            $table->string('entity_type', 120)->nullable();
            $table->text('description')->nullable();
            $table->json('columns')->nullable();
            $table->json('filters')->nullable();
            $table->json('groupings')->nullable();
            $table->json('metrics')->nullable();
            $table->json('permissions')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('cache_ttl_minutes')->default(10);
            $table->boolean('is_exportable')->default(false);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['module', 'is_active'], 'dyn_report_templates_module_idx');
            $table->index(['entity_type', 'is_active'], 'dyn_report_templates_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_report_templates');
        Schema::dropIfExists('dynamic_document_templates');
    }
};
