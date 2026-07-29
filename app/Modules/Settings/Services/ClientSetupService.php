<?php

namespace App\Modules\Settings\Services;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Branches\Models\BranchSetting;
use App\Modules\Customers\Models\CustomerType;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Sales\Models\AdvanceOption;
use App\Modules\Sales\Models\Currency;
use App\Modules\Sales\Models\SaleType;
use App\Support\AuthSessionCache;
use App\Support\SystemRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class ClientSetupService
{
    public function setup(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $branch = Branch::query()->updateOrCreate(
                ['code' => Str::upper($data['branch_code'] ?? 'CENTRAL')],
                [
                    'name' => $data['branch_name'] ?? 'Sucursal Central',
                    'barcode' => $data['branch_barcode'] ?? 'BR-'.Str::upper($data['branch_code'] ?? 'CENTRAL'),
                    'address' => $data['branch_address'] ?? null,
                    'phone' => $data['branch_phone'] ?? null,
                    'is_active' => true,
                ],
            );

            BranchSetting::query()->updateOrCreate(
                ['branch_id' => $branch->id],
                [
                    'primary_color' => $data['primary_color'] ?? '#0ea5e9',
                    'secondary_color' => $data['secondary_color'] ?? '#0f172a',
                    'theme_mode' => $data['theme_mode'] ?? 'light',
                    'options' => ['business_name' => $data['business_name'] ?? 'Negocio'],
                ],
            );

            Currency::query()->firstOrCreate(['code' => 'BOB'], ['name' => 'Bolivianos', 'symbol' => 'Bs', 'exchange_rate_to_bob' => 1, 'is_base' => true, 'is_active' => true]);
            CustomerType::query()->firstOrCreate(['name' => 'Ocasionales'], ['is_active' => true]);
            SaleType::query()->firstOrCreate(['name' => 'Ocasionales'], ['is_active' => true]);
            AdvanceOption::query()->firstOrCreate(['name' => 'Sin anticipo'], ['type' => 'amount', 'percentage' => 0, 'amount' => 0, 'is_active' => true]);

            foreach ($this->defaultPaymentMethods() as $method) {
                PaymentMethod::query()->updateOrCreate(['code' => $method['code']], $method);
            }

            $systemRole = Role::firstOrCreate(['name' => SystemRoles::SYSTEM_SUPERADMIN, 'guard_name' => 'web']);
            $systemRole->syncPermissions(Permission::query()->where('guard_name', 'web')->pluck('name')->all());
            $adminRole = Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);
            $adminRole->syncPermissions(Permission::query()->where('guard_name', 'web')->pluck('name')->all());

            $systemUser = User::query()->updateOrCreate(
                ['email' => $data['system_email'] ?? 'mrrobotbolivia@gmail.com'],
                [
                    'name' => 'Mr. Robot Bolivia',
                    'branch_id' => $branch->id,
                    'password' => Hash::make($data['system_password'] ?? 'mrrobot2026'),
                    'is_active' => true,
                    'force_password_change' => false,
                ],
            );
            $systemUser->syncRoles([$systemRole->name]);
            $systemUser->accessibleBranches()->syncWithoutDetaching([$branch->id]);

            $admin = User::query()->updateOrCreate(
                ['email' => $data['admin_email'] ?? 'admin@calamina.local'],
                [
                    'name' => $data['admin_name'] ?? 'Administrador',
                    'branch_id' => $branch->id,
                    'password' => Hash::make($data['admin_password'] ?? 'admin123456'),
                    'is_active' => true,
                    'force_password_change' => true,
                ],
            );
            $admin->syncRoles([$adminRole->name]);
            $admin->accessibleBranches()->syncWithoutDetaching([$branch->id]);

            AuthSessionCache::bump();

            app(ProductionLogService::class)->record('maintenance', 'info', 'client_setup_completed', 'Configuracion inicial de cliente completada.', [
                'branch_id' => $branch->id,
                'admin_email' => $admin->email,
            ]);

            return [
                'branch' => $branch,
                'admin' => $admin,
                'system_user' => $systemUser,
            ];
        });
    }

    private function defaultPaymentMethods(): array
    {
        return [
            ['code' => 'cash', 'name' => 'Efectivo', 'requires_reference' => false, 'is_active' => true],
            ['code' => 'qr', 'name' => 'QR', 'requires_reference' => true, 'is_active' => true],
            ['code' => 'transfer', 'name' => 'Transferencia', 'requires_reference' => true, 'is_active' => true],
        ];
    }
}
