<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('document_type', 24)->default('both')->index();
            $table->string('paper_type', 24)->default('letter')->index();
            $table->unsignedSmallInteger('thermal_width_mm')->nullable();
            $table->boolean('use_branding')->default(true)->index();
            $table->json('layout');
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'document_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_templates');
    }
};
