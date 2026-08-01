<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_reservations', function (Blueprint $table) {
            $table->foreignId('rescheduled_from_id')->nullable()->after('user_id')->constrained('business_reservations')->nullOnDelete();
            $table->string('appointment_kind', 80)->nullable()->after('type')->index();
            $table->string('confirmation_status', 40)->default('pending')->after('status')->index();
            $table->dateTime('confirmed_at')->nullable()->after('end_at');
            $table->dateTime('reminder_at')->nullable()->after('confirmed_at')->index();
            $table->dateTime('cancelled_at')->nullable()->after('reminder_at');
            $table->dateTime('no_show_at')->nullable()->after('cancelled_at');
            $table->dateTime('rescheduled_at')->nullable()->after('no_show_at');
            $table->index(['branch_id', 'appointment_kind', 'start_at'], 'business_reservations_branch_kind_start_idx');
            $table->index(['branch_id', 'confirmation_status', 'start_at'], 'business_reservations_branch_confirm_start_idx');
        });
    }

    public function down(): void
    {
        Schema::table('business_reservations', function (Blueprint $table) {
            $table->dropIndex('business_reservations_branch_kind_start_idx');
            $table->dropIndex('business_reservations_branch_confirm_start_idx');
            $table->dropConstrainedForeignId('rescheduled_from_id');
            $table->dropColumn([
                'appointment_kind',
                'confirmation_status',
                'confirmed_at',
                'reminder_at',
                'cancelled_at',
                'no_show_at',
                'rescheduled_at',
            ]);
        });
    }
};
