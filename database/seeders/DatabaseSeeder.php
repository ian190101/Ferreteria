<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Banks\Models\BankAccount;
use App\Modules\Branches\Models\Branch;
use App\Modules\Branches\Models\BranchSetting;
use App\Modules\Customers\Models\CustomerType;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Inventory\Models\Thickness;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Sales\Models\AdvanceOption;
use App\Modules\Sales\Models\Currency;
use App\Modules\Sales\Models\DocumentSequence;
use App\Modules\Sales\Models\ReceiptTemplate;
use App\Modules\Sales\Models\SaleType;
use App\Modules\Settings\Models\SystemSetting;
use App\Support\DecimalPrecision;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'alerts.view',
            'dashboard.view',
            'banks.view',
            'banks.manage',
            'branches.view',
            'branches.manage',
            'cash.view',
            'cash.manage',
            'customers.view',
            'customers.manage',
            'credit-notes.view',
            'credit-notes.manage',
            'expenses.view',
            'expenses.manage',
            'inventory.products.view',
            'inventory.products.manage',
            'inventory.coils.manage',
            'inventory.adjustments.view',
            'inventory.adjustments.manage',
            'inventory.movements.view',
            'inventory.reservations.view',
            'inventory.reservations.manage',
            'inventory.transfers.view',
            'inventory.transfers.manage',
            'purchases.view',
            'purchases.manage',
            'payments.view',
            'payments.manage',
            'payment-promises.view',
            'payment-promises.manage',
            'production.view',
            'production.manage',
            'sales.view',
            'sales.manage',
            'sales.prices.override',
            'sales.deliveries.view',
            'sales.deliveries.manage',
            'sales.returns.view',
            'sales.returns.manage',
            'users.view',
            'users.manage',
            'settings.manage',
            'audit.view',
            'reports.view',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $superadmin = Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);
        $superadmin->syncPermissions($permissions);

        $branch = Branch::updateOrCreate(
            ['code' => 'CENTRAL'],
            [
                'name' => 'Sucursal Central',
                'barcode' => 'BR-CENTRAL',
                'phone' => '77300567',
                'secondary_phone' => '69010531',
                'point_of_sale_name' => 'Doble via',
                'address' => 'Av. Doble via la guardia km8 a lado del restaurante los patos',
                'is_active' => true,
            ],
        );

        BranchSetting::firstOrCreate(
            ['branch_id' => $branch->id],
            [
                'primary_color' => '#2563eb',
                'secondary_color' => '#0f172a',
                'theme_mode' => 'system',
            ],
        );

        Thickness::firstOrCreate(
            ['millimeters' => 0.5000],
            [
                'name' => '0.50 mm',
                'kg_per_meter' => 0.100000,
                'kg_to_meter_factor' => 10.000000,
                'is_active' => true,
            ],
        );

        SaleType::firstOrCreate(['name' => 'Ocasionales'], ['is_active' => true]);
        CustomerType::firstOrCreate(['name' => 'Ocasionales'], ['is_active' => true]);

        Currency::firstOrCreate(
            ['code' => 'BOB'],
            [
                'name' => 'Bolivianos',
                'symbol' => 'Bs',
                'exchange_rate_to_bob' => 1,
                'is_base' => true,
                'is_active' => true,
            ],
        );

        Currency::firstOrCreate(
            ['code' => 'USD'],
            [
                'name' => 'Dolares',
                'symbol' => '$',
                'exchange_rate_to_bob' => 10,
                'is_base' => false,
                'is_active' => true,
            ],
        );

        foreach ([10, 30, 50] as $percentage) {
            AdvanceOption::firstOrCreate(
                ['percentage' => $percentage],
                ['name' => "{$percentage}%", 'type' => AdvanceOption::TYPE_PERCENTAGE, 'amount' => null, 'is_active' => true],
            );
        }

        foreach ([
            ['document_type' => 'quotation', 'name' => 'Cotizaciones central', 'prefix' => 'COT-', 'next_number' => 1, 'padding' => 6],
            ['document_type' => 'sale_note', 'name' => 'Notas central', 'prefix' => 'NV-', 'next_number' => 1, 'padding' => 6],
        ] as $sequence) {
            DocumentSequence::firstOrCreate(
                ['branch_id' => $branch->id, 'document_type' => $sequence['document_type']],
                [...$sequence, 'is_active' => true],
            );
        }

        foreach ([
            ['name' => 'Efectivo', 'code' => 'cash', 'requires_reference' => false],
            ['name' => 'Transferencia', 'code' => 'transfer', 'requires_reference' => true],
            ['name' => 'QR', 'code' => 'qr', 'requires_reference' => true],
        ] as $method) {
            PaymentMethod::firstOrCreate(
                ['code' => $method['code']],
                ['name' => $method['name'], 'requires_reference' => $method['requires_reference'], 'is_active' => true],
            );
        }

        foreach ([
            ['name' => 'Servicios basicos', 'code' => 'services'],
            ['name' => 'Transporte', 'code' => 'transport'],
            ['name' => 'Mantenimiento', 'code' => 'maintenance'],
            ['name' => 'Administracion', 'code' => 'admin'],
        ] as $category) {
            ExpenseCategory::firstOrCreate(
                ['code' => $category['code']],
                ['name' => $category['name'], 'is_active' => true],
            );
        }

        BankAccount::firstOrCreate(
            ['branch_id' => $branch->id, 'account_number' => 'BANCO-PRINCIPAL'],
            [
                'name' => 'Cuenta principal',
                'bank_name' => 'Banco local',
                'currency_code' => 'BOB',
                'opening_balance' => 0,
                'current_balance' => 0,
                'is_active' => true,
            ],
        );

        ReceiptTemplate::firstOrCreate(
            ['name' => 'Formato principal', 'document_type' => 'both', 'branch_id' => null],
            [
                'paper_type' => 'letter',
                'thermal_width_mm' => 80,
                'use_branding' => true,
                'layout' => ReceiptTemplate::defaultLayout(),
                'is_default' => true,
                'is_active' => true,
            ],
        );

        foreach ([
            ['group' => 'performance', 'key' => 'cache_ttl_minutes', 'value' => ['value' => '5'], 'description' => 'Minutos de cache para resumenes operativos'],
            ['group' => 'security', 'key' => 'session_timeout_minutes', 'value' => ['value' => '120'], 'description' => 'Minutos sugeridos de vida de sesion'],
            ['group' => 'maintenance', 'key' => 'backup_retention_days', 'value' => ['value' => '30'], 'description' => 'Dias de retencion para backups operativos'],
            ['group' => 'formatos', 'key' => DecimalPrecision::SETTING_KEY, 'value' => DecimalPrecision::defaults(), 'description' => 'Gestion de decimales globales y por modulo', 'is_public' => true],
        ] as $setting) {
            SystemSetting::firstOrCreate(
                ['key' => $setting['key']],
                [
                    'group' => $setting['group'],
                    'value' => $setting['value'],
                    'description' => $setting['description'],
                    'is_public' => $setting['is_public'] ?? false,
                ],
            );
        }

        $user = User::firstOrCreate([
            'email' => 'admin@calmina.local',
        ], [
            'branch_id' => $branch->id,
            'name' => 'Administrador',
            'is_active' => true,
            'password' => Hash::make('admin12345'),
        ]);

        $user->assignRole($superadmin);
        $user->accessibleBranches()->syncWithoutDetaching([$branch->id]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
