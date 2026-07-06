<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('group', 80)->index();
            $table->string('key', 120)->unique();
            $table->json('value')->nullable();
            $table->string('description', 255)->nullable();
            $table->boolean('is_public')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('maintenance_backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 40)->default('local');
            $table->string('path', 255);
            $table->string('status', 40)->default('created')->index();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_backups');
        Schema::dropIfExists('system_settings');
    }
};
