<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_document_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('document_type', 60)->index();
            $table->string('name');
            $table->string('paper_type', 40)->default('letter');
            $table->unsignedSmallInteger('thermal_width_mm')->nullable();
            $table->json('layout')->nullable();
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'document_type', 'is_active'], 'print_templates_branch_type_idx');
        });

        Schema::create('print_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('printer_profile_id')->nullable()->constrained('printer_profiles')->nullOnDelete();
            $table->foreignId('print_document_template_id')->nullable()->constrained('print_document_templates')->nullOnDelete();
            $table->string('document_type', 60)->index();
            $table->string('area', 60)->default('cashier')->index();
            $table->string('trigger', 80)->default('manual')->index();
            $table->unsignedSmallInteger('copies')->default(1);
            $table->boolean('auto_print')->default(false)->index();
            $table->json('conditions')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['branch_id', 'document_type', 'area', 'trigger'], 'print_rules_branch_doc_area_trigger_unique');
        });

        Schema::create('print_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('printer_profile_id')->nullable()->constrained('printer_profiles')->nullOnDelete();
            $table->foreignId('print_document_template_id')->nullable()->constrained('print_document_templates')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('document_type', 60)->index();
            $table->nullableMorphs('printable');
            $table->string('area', 60)->default('cashier')->index();
            $table->string('status', 40)->default('queued')->index();
            $table->unsignedSmallInteger('copies')->default(1);
            $table->json('payload')->nullable();
            $table->text('rendered_preview')->nullable();
            $table->text('error_message')->nullable();
            $table->dateTime('printed_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status', 'created_at'], 'print_jobs_branch_status_created_idx');
        });

        foreach (['printing.view', 'printing.manage', 'printing.jobs.manage'] as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission, 'guard_name' => 'web'],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
        Schema::dropIfExists('print_rules');
        Schema::dropIfExists('print_document_templates');

        DB::table('permissions')
            ->whereIn('name', ['printing.view', 'printing.manage', 'printing.jobs.manage'])
            ->delete();
    }
};
