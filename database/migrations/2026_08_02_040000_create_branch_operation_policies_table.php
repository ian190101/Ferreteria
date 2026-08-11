<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_operation_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('code', 120)->unique();
            $table->string('name', 180);
            $table->string('operation', 80)->index();
            $table->string('scope', 60)->default('global')->index();
            $table->string('enforcement_mode', 60)->default('monitor')->index();
            $table->json('applies_to_modules')->nullable();
            $table->json('rules')->nullable();
            $table->json('permissions')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(['operation', 'branch_id', 'is_active'], 'branch_operation_policies_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_operation_policies');
    }
};
