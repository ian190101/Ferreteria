<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_assistant_api_logs')) {
            Schema::create('ai_assistant_api_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ai_assistant_api_client_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('endpoint', 120)->index();
                $table->string('scope', 80)->nullable()->index();
                $table->string('status', 30)->index();
                $table->unsignedSmallInteger('http_status')->nullable();
                $table->string('ip_hash', 128)->nullable();
                $table->string('user_agent_hash', 128)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_assistant_api_logs');
    }
};
