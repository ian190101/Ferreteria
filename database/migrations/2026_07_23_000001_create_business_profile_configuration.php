<?php

use App\Support\SystemRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->updateOrInsert(
            ['name' => SystemRoles::SYSTEM_SUPERADMIN, 'guard_name' => 'web'],
            ['updated_at' => now(), 'created_at' => now()],
        );

        $systemRoleId = DB::table('roles')
            ->where('name', SystemRoles::SYSTEM_SUPERADMIN)
            ->where('guard_name', 'web')
            ->value('id');

        if ($systemRoleId) {
            DB::table('permissions')
                ->where('guard_name', 'web')
                ->pluck('id')
                ->each(function ($permissionId) use ($systemRoleId) {
                    DB::table('role_has_permissions')->updateOrInsert([
                        'permission_id' => $permissionId,
                        'role_id' => $systemRoleId,
                    ]);
                });
        }

        Schema::create('business_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('business_type', 80)->index();
            $table->string('status', 30)->default('active')->index();
            $table->json('configuration');
            $table->timestamp('applied_at')->nullable()->index();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('business_profile_drafts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('business_type', 80)->index();
            $table->string('status', 30)->default('draft')->index();
            $table->json('configuration');
            $table->foreignId('source_profile_id')->nullable()->constrained('business_profiles')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('business_profile_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_profile_id')->nullable()->constrained('business_profiles')->nullOnDelete();
            $table->unsignedInteger('version_number')->default(1);
            $table->string('name');
            $table->string('business_type', 80)->index();
            $table->json('configuration');
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable()->index();
            $table->timestamps();

            $table->index(['business_profile_id', 'version_number'], 'bp_versions_profile_version_idx');
        });

        DB::table('business_profiles')->insert([
            'name' => 'Ferreteria con cotizacion y nota de venta',
            'business_type' => 'hardware_store',
            'status' => 'active',
            'configuration' => json_encode($this->defaultConfiguration(), JSON_THROW_ON_ERROR),
            'applied_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('business_profile_versions');
        Schema::dropIfExists('business_profile_drafts');
        Schema::dropIfExists('business_profiles');

        $systemRoleId = DB::table('roles')->where('name', SystemRoles::SYSTEM_SUPERADMIN)->value('id');

        if ($systemRoleId) {
            DB::table('role_has_permissions')->where('role_id', $systemRoleId)->delete();
            DB::table('model_has_roles')->where('role_id', $systemRoleId)->delete();
            DB::table('roles')->where('id', $systemRoleId)->delete();
        }
    }

    private function defaultConfiguration(): array
    {
        return [
            'modules' => [
                'quotes' => true,
                'sales_notes' => true,
                'pos' => false,
                'purchases' => true,
                'quick_purchases' => false,
                'cash' => true,
                'banks' => true,
                'inventory' => true,
                'deliveries' => true,
                'customers' => true,
                'suppliers' => true,
                'offline_pos' => false,
            ],
            'sales' => [
                'workflow' => 'quotation_to_sale_note',
                'quotation_mode' => 'required',
                'document_main' => 'sale_note',
                'customer_required' => true,
                'allow_occasional_customer' => true,
                'allow_price_override' => 'permission',
                'allow_negative_stock' => false,
                'inventory_discount_timing' => 'sale_note',
            ],
            'purchases' => [
                'workflow' => 'standard_purchase',
                'barcode_entry' => false,
                'allow_create_product' => true,
                'register_expense_when_paid' => true,
            ],
            'cash' => [
                'required_to_sell' => true,
                'scope' => 'user_branch',
                'bank_reconciliation' => true,
                'allow_offline_cash_sales' => false,
            ],
            'inventory' => [
                'always_by_branch' => true,
                'lot_tracking_optional' => true,
                'unit_conversions' => true,
            ],
            'ux' => [
                'context_help' => true,
                'spanish_messages' => true,
                'responsive_tables' => true,
                'demo_mode' => true,
            ],
        ];
    }
};
