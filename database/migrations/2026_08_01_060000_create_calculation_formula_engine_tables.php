<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calculation_formulas', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 120)->nullable()->index();
            $table->string('code', 120);
            $table->string('name', 180);
            $table->text('description')->nullable();
            $table->string('result_type', 40)->default('decimal');
            $table->json('expression');
            $table->json('variables')->nullable();
            $table->unsignedTinyInteger('precision')->default(2);
            $table->json('permissions')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['entity_type', 'code'], 'calculation_formulas_entity_code_unique');
            $table->index(['entity_type', 'is_active'], 'calculation_formulas_entity_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calculation_formulas');
    }
};
