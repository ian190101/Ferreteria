<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reservable_resource_id')->nullable()->constrained('reservable_resources')->nullOnDelete();
            $table->foreignId('worker_id')->nullable()->constrained('workers')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reservation_number')->unique();
            $table->string('type', 30)->default('reservation')->index();
            $table->string('channel', 60)->nullable()->index();
            $table->string('status', 40)->default('pending')->index();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 80)->nullable();
            $table->string('title');
            $table->dateTime('start_at')->index();
            $table->dateTime('end_at')->index();
            $table->dateTime('checked_out_at')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->decimal('amount', 14, 4)->default(0);
            $table->decimal('advance_amount', 14, 4)->default(0);
            $table->decimal('deposit_amount', 14, 4)->default(0);
            $table->decimal('penalty_amount', 14, 4)->default(0);
            $table->decimal('total_amount', 14, 4)->default(0);
            $table->text('condition_before')->nullable();
            $table->text('condition_after')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status', 'start_at'], 'business_reservations_branch_status_start_idx');
            $table->index(['reservable_resource_id', 'start_at', 'end_at'], 'business_reservations_resource_range_idx');
            $table->index(['customer_id', 'status'], 'business_reservations_customer_status_idx');
        });

        Schema::create('business_reservation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_reservation_id')->constrained('business_reservations')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 14, 4)->default(1);
            $table->string('unit_label', 40)->nullable();
            $table->decimal('unit_price', 14, 4)->default(0);
            $table->decimal('subtotal', 14, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'business_reservation_id'], 'business_reservation_items_product_idx');
        });

        foreach ([
            'reservations.view',
            'reservations.manage',
            'rentals.view',
            'rentals.manage',
        ] as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission, 'guard_name' => 'web'],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('business_reservation_items');
        Schema::dropIfExists('business_reservations');

        DB::table('permissions')
            ->whereIn('name', ['reservations.view', 'reservations.manage', 'rentals.view', 'rentals.manage'])
            ->delete();
    }
};
