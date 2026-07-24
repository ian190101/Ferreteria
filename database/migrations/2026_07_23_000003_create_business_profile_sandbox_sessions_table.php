<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_profile_sandbox_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name')->default('Demo sandbox');
            $table->string('database_name')->nullable()->index();
            $table->json('payload');
            $table->string('status', 30)->default('active')->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'status', 'last_activity_at'], 'bp_sandbox_user_status_activity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_profile_sandbox_sessions');
    }
};
