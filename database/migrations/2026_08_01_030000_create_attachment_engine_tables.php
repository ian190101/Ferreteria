<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachment_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 120)->index();
            $table->string('code', 120);
            $table->string('label', 180);
            $table->string('purpose', 80)->default('evidence')->index();
            $table->json('allowed_mime_types')->nullable();
            $table->json('allowed_extensions')->nullable();
            $table->unsignedInteger('max_file_mb')->default(10);
            $table->string('storage_disk', 80)->nullable();
            $table->string('path_prefix', 180)->default('business-attachments');
            $table->boolean('is_required')->default(false);
            $table->boolean('is_sensitive')->default(false)->index();
            $table->boolean('requires_signed_url')->default(true);
            $table->boolean('audit_downloads')->default(true);
            $table->boolean('visible_in_documents')->default(false);
            $table->boolean('visible_in_reports')->default(false);
            $table->json('permissions')->nullable();
            $table->json('metadata')->nullable();
            $table->string('retention_policy', 80)->default('standard');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['entity_type', 'code'], 'attachment_definition_entity_code_unique');
            $table->index(['entity_type', 'is_active', 'sort_order'], 'attachment_definition_entity_idx');
        });

        Schema::create('business_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('attachment_definition_id')->nullable();
            $table->string('entity_type', 120)->index();
            $table->string('record_id', 120)->index();
            $table->string('original_name', 255);
            $table->string('disk', 80);
            $table->string('path', 500);
            $table->string('mime_type', 160)->nullable();
            $table->string('extension', 30)->nullable()->index();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum', 128)->nullable()->index();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->boolean('is_sensitive')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->foreign('attachment_definition_id', 'business_attachment_definition_fk')
                ->references('id')
                ->on('attachment_definitions')
                ->nullOnDelete();
            $table->index(['entity_type', 'record_id', 'is_active'], 'business_attachment_record_idx');
            $table->index(['attachment_definition_id', 'is_active'], 'business_attachment_definition_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_attachments');
        Schema::dropIfExists('attachment_definitions');
    }
};
