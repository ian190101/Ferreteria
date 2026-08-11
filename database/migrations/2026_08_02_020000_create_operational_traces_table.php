<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_traces', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 80)->index();
            $table->unsignedBigInteger('entity_id')->nullable()->index();
            $table->string('event', 120)->index();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('source', 80)->default('system')->index();
            $table->string('status', 40)->default('completed')->index();
            $table->json('metadata')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id', 'event'], 'operational_traces_entity_event_idx');
            $table->index(['actor_id', 'occurred_at'], 'operational_traces_actor_date_idx');
            $table->index(['branch_id', 'occurred_at'], 'operational_traces_branch_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_traces');
    }
};
