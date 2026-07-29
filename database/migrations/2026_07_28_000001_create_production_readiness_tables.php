<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 60)->index();
            $table->string('level', 20)->default('info')->index();
            $table->string('event', 120)->index();
            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address', 80)->nullable();
            $table->timestamps();

            $table->index(['channel', 'level', 'created_at']);
        });

        Schema::create('application_licenses', function (Blueprint $table) {
            $table->id();
            $table->string('holder_name');
            $table->string('nit', 30)->nullable()->index();
            $table->string('domain', 160)->nullable()->index();
            $table->string('license_key', 160)->unique();
            $table->date('support_until')->nullable()->index();
            $table->json('allowed_modules')->nullable();
            $table->string('activation_mode', 30)->default('offline');
            $table->string('status', 30)->default('active')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('module', 60)->index();
            $table->string('file_name');
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('created_rows')->default(0);
            $table->unsignedInteger('updated_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->json('errors')->nullable();
            $table->timestamps();

            $table->index(['module', 'status', 'created_at']);
        });

        $permissions = [
            'maintenance.view',
            'maintenance.manage',
            'imports.view',
            'imports.manage',
            'licenses.view',
            'licenses.manage',
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission, 'guard_name' => 'web'],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }

        $permissionIds = DB::table('permissions')->whereIn('name', $permissions)->pluck('id');
        $roleIds = DB::table('roles')->whereIn('name', ['superadmin', 'sistemasuperadmin'])->pluck('id');

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
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('application_licenses');
        Schema::dropIfExists('maintenance_logs');

        $permissions = [
            'maintenance.view',
            'maintenance.manage',
            'imports.view',
            'imports.manage',
            'licenses.view',
            'licenses.manage',
        ];
        $permissionIds = DB::table('permissions')->whereIn('name', $permissions)->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
