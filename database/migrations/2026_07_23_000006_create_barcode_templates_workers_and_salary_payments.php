<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('barcode_label_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('name');
            $table->string('paper_type', 40)->default('label_50x30');
            $table->unsignedSmallInteger('label_width_mm')->default(50);
            $table->unsignedSmallInteger('label_height_mm')->default(30);
            $table->unsignedSmallInteger('margin_mm')->default(2);
            $table->unsignedSmallInteger('barcode_height_mm')->default(12);
            $table->unsignedSmallInteger('font_size')->default(9);
            $table->boolean('show_product_name')->default(true);
            $table->boolean('show_sku')->default(true);
            $table->boolean('show_price')->default(false);
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('workers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('name');
            $table->string('document_number', 80)->nullable()->index();
            $table->string('phone', 80)->nullable();
            $table->string('position', 120)->nullable();
            $table->date('hired_at')->nullable();
            $table->decimal('salary_amount', 18, 2)->default(0);
            $table->string('salary_frequency', 40)->default('monthly');
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('salary_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->timestamp('paid_at')->nullable()->index();
            $table->decimal('amount', 18, 2);
            $table->string('reference', 160)->nullable();
            $table->string('status', 30)->default('paid')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $permissions = [
            'barcode-labels.view',
            'barcode-labels.manage',
            'workers.view',
            'workers.manage',
            'payroll.view',
            'payroll.manage',
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission, 'guard_name' => 'web'],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', $permissions)
            ->pluck('id');
        $roleIds = DB::table('roles')
            ->whereIn('name', ['superadmin', 'sistemasuperadmin'])
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_has_permissions')->updateOrInsert([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_payments');
        Schema::dropIfExists('workers');
        Schema::dropIfExists('barcode_label_templates');

        $permissionNames = [
            'barcode-labels.view',
            'barcode-labels.manage',
            'workers.view',
            'workers.manage',
            'payroll.view',
            'payroll.manage',
        ];
        $permissionIds = DB::table('permissions')->whereIn('name', $permissionNames)->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('name', $permissionNames)->delete();
    }
};
