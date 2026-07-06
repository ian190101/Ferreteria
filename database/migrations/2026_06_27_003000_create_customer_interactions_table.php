<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40)->index();
            $table->string('status', 40)->default('pending')->index();
            $table->dateTime('contact_at')->index();
            $table->dateTime('follow_up_at')->nullable()->index();
            $table->string('subject', 160);
            $table->text('notes')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status', 'follow_up_at']);
            $table->index(['user_id', 'status', 'follow_up_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_interactions');
    }
};
