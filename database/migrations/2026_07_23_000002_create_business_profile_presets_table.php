<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_profile_presets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('business_type', 80)->index();
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false)->index();
            $table->json('configuration');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['name', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_profile_presets');
    }
};
