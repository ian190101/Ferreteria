<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Banks\Models\BankAccount;
use App\Modules\Branches\Models\Branch;
use App\Modules\Branches\Models\BranchSetting;
use App\Modules\Customers\Models\CustomerType;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Inventory\Models\BarcodeLabelTemplate;
use App\Modules\Inventory\Models\Thickness;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Sales\Models\AdvanceOption;
use App\Modules\Sales\Models\Currency;
use App\Modules\Sales\Models\DocumentSequence;
use App\Modules\Sales\Models\ReceiptTemplate;
use App\Modules\Sales\Models\SaleType;
use App\Modules\Settings\Models\SystemSetting;
use App\Modules\SystemSuperadmin\Models\BusinessProfilePreset;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use App\Support\DecimalPrecision;
use App\Support\SystemRoles;
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
            'billing.view',
            'billing.manage',
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
            'barcode-labels.view',
            'barcode-labels.manage',
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
            'workers.view',
            'workers.manage',
            'payroll.view',
            'payroll.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $superadmin = Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);
        $superadmin->syncPermissions($permissions);
        $systemSuperadmin = Role::firstOrCreate(['name' => SystemRoles::SYSTEM_SUPERADMIN, 'guard_name' => 'web']);
        $systemSuperadmin->syncPermissions($permissions);

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

        ExpenseCategory::query()->updateOrCreate(
            ['code' => ExpenseCategory::SALARY_PAYROLL_CODE],
            ['name' => ExpenseCategory::SALARY_PAYROLL_NAME, 'is_active' => true],
        );

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

        BarcodeLabelTemplate::query()->firstOrCreate(
            ['name' => 'Etiqueta principal', 'branch_id' => null],
            [
                'paper_type' => 'label_50x30',
                'label_width_mm' => 50,
                'label_height_mm' => 30,
                'margin_mm' => 2,
                'barcode_height_mm' => 14,
                'font_size' => 9,
                'show_product_name' => true,
                'show_sku' => false,
                'show_price' => false,
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

        if (filled(env('SYSTEM_SUPERADMIN_EMAIL')) && filled(env('SYSTEM_SUPERADMIN_PASSWORD'))) {
            $masterUser = User::firstOrCreate([
                'email' => env('SYSTEM_SUPERADMIN_EMAIL'),
            ], [
                'branch_id' => $branch->id,
                'name' => env('SYSTEM_SUPERADMIN_NAME', 'Mr. Robot Bolivia'),
                'is_active' => true,
                'password' => Hash::make(env('SYSTEM_SUPERADMIN_PASSWORD')),
            ]);

            $masterUser->assignRole($systemSuperadmin);
            $masterUser->accessibleBranches()->syncWithoutDetaching([$branch->id]);
        }

        $this->seedBusinessProfilePresets($user->id);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seedBusinessProfilePresets(int $userId): void
    {
        $base = BusinessProfileConfiguration::defaults();

        foreach ($this->businessProfilePresets($base) as $preset) {
            BusinessProfilePreset::withTrashed()->updateOrCreate(
                ['name' => $preset['name']],
                [
                    'business_type' => $preset['business_type'],
                    'description' => $preset['description'],
                    'is_system' => true,
                    'configuration' => BusinessProfileConfiguration::normalized($preset['configuration']),
                    'created_by' => $userId,
                    'updated_by' => $userId,
                    'deleted_at' => null,
                ],
            );
        }
    }

    private function businessProfilePresets(array $base): array
    {
        return [
            [
                'name' => 'Ferreteria con cotizacion y nota',
                'business_type' => 'hardware_store',
                'description' => 'Flujo formal: cotizacion obligatoria, nota de venta, caja, bancos, inventario y despachos opcionales.',
                'configuration' => [
                    ...$base,
                    'modules' => [...$base['modules'], 'quotes' => true, 'sales_notes' => true, 'pos' => false, 'purchases' => true, 'quick_purchases' => false, 'cash' => true, 'banks' => true, 'billing' => true, 'inventory' => true, 'deliveries' => true],
                    'sales' => [...$base['sales'], 'workflow' => 'quotation_to_sale_note', 'quotation_mode' => 'required', 'document_main' => 'sale_note', 'customer_mode' => 'required', 'inventory_discount_timing' => 'sale_note', 'price_policy' => 'mixed', 'discount_policy' => 'permission', 'max_discount_percent' => 10, 'credit_limit_policy' => 'warn', 'default_credit_limit' => 1000, 'negative_stock_policy' => 'never'],
                    'billing' => [...$base['billing'], 'enabled' => true, 'invoice_flow' => 'quote_sale_note_invoice', 'issue_from' => 'sale_note', 'issue_timing' => 'automatic_after_quote_conversion'],
                    'purchases' => [...$base['purchases'], 'workflow' => 'standard_purchase', 'barcode_entry' => false, 'allow_create_product' => true],
                ],
            ],
            [
                'name' => 'Ferreteria POS',
                'business_type' => 'hardware_store',
                'description' => 'Ferreteria con venta rapida por POS, compras por barcode y cotizacion opcional.',
                'configuration' => [
                    ...$base,
                    'modules' => [...$base['modules'], 'quotes' => true, 'sales_notes' => true, 'pos' => true, 'quick_purchases' => true, 'cash' => true, 'banks' => true, 'billing' => true, 'inventory' => true],
                    'sales' => [...$base['sales'], 'workflow' => 'optional_quotation', 'quotation_mode' => 'optional', 'document_main' => 'ticket', 'customer_mode' => 'optional', 'price_policy' => 'branch_price', 'discount_policy' => 'permission', 'max_discount_percent' => 5, 'credit_limit_policy' => 'warn', 'default_credit_limit' => 500, 'negative_stock_policy' => 'role', 'negative_stock_roles' => ['superadmin']],
                    'billing' => [...$base['billing'], 'enabled' => true, 'invoice_flow' => 'choose_per_sale', 'issue_from' => 'manual_choice', 'issue_timing' => 'manual'],
                    'purchases' => [...$base['purchases'], 'workflow' => 'barcode_purchase', 'barcode_entry' => true],
                    'pos' => [...$base['pos'], 'scanner_mode' => 'optional'],
                    'cash' => [...$base['cash'], 'scope' => 'pos_terminal'],
                ],
            ],
            [
                'name' => 'Supermercado',
                'business_type' => 'supermarket',
                'description' => 'POS rapido sin cotizaciones, cliente oculto, caja obligatoria y lector de barras.',
                'configuration' => [
                    ...$base,
                    'modules' => [...$base['modules'], 'quotes' => false, 'sales_notes' => true, 'pos' => true, 'billing' => true, 'deliveries' => false, 'customers' => false, 'payment_promises' => false],
                    'sales' => [...$base['sales'], 'workflow' => 'pos', 'quotation_mode' => 'disabled', 'document_main' => 'invoice_direct', 'customer_mode' => 'hidden', 'price_policy' => 'branch_price', 'discount_policy' => 'never', 'credit_limit_policy' => 'disabled', 'negative_stock_policy' => 'never', 'payment_methods_by_flow' => ['sales' => ['cash', 'qr', 'card'], 'pos' => ['cash', 'qr', 'card'], 'collections' => ['cash', 'qr', 'card']]],
                    'billing' => [...$base['billing'], 'enabled' => true, 'invoice_flow' => 'direct_invoice', 'issue_from' => 'pos', 'issue_timing' => 'automatic_direct'],
                    'pos' => [...$base['pos'], 'scanner_mode' => 'required', 'customer_prompt' => 'hidden'],
                    'deliveries' => [...$base['deliveries'], 'mode' => 'disabled'],
                    'products' => [...$base['products'], 'catalog_mode' => 'barcode_retail', 'barcode_required' => true],
                ],
            ],
            [
                'name' => 'Libreria y papeleria',
                'business_type' => 'stationery',
                'description' => 'Tienda retail con POS, compras rapidas, cliente opcional y sin despachos.',
                'configuration' => [
                    ...$base,
                    'modules' => [...$base['modules'], 'quotes' => false, 'sales_notes' => true, 'pos' => true, 'deliveries' => false],
                    'sales' => [...$base['sales'], 'workflow' => 'pos', 'quotation_mode' => 'disabled', 'document_main' => 'ticket', 'customer_mode' => 'optional', 'price_policy' => 'base_price', 'discount_policy' => 'always_with_limit', 'max_discount_percent' => 5, 'credit_limit_policy' => 'disabled', 'negative_stock_policy' => 'never'],
                    'deliveries' => [...$base['deliveries'], 'mode' => 'disabled'],
                    'products' => [...$base['products'], 'catalog_mode' => 'barcode_retail'],
                ],
            ],
            [
                'name' => 'Servicios',
                'business_type' => 'services',
                'description' => 'Ventas de servicios sin inventario obligatorio ni compras operativas.',
                'configuration' => [
                    ...$base,
                    'modules' => [...$base['modules'], 'quotes' => true, 'sales_notes' => true, 'pos' => false, 'purchases' => false, 'quick_purchases' => false, 'inventory' => false, 'deliveries' => false, 'suppliers' => false],
                    'sales' => [...$base['sales'], 'workflow' => 'service_sale', 'quotation_mode' => 'optional', 'document_main' => 'receipt', 'customer_mode' => 'required', 'inventory_discount_timing' => 'manual', 'price_policy' => 'customer_price', 'discount_policy' => 'permission', 'max_discount_percent' => 20, 'credit_limit_policy' => 'block', 'default_credit_limit' => 2000],
                    'products' => [...$base['products'], 'catalog_mode' => 'services', 'allow_service_items' => true],
                ],
            ],
            [
                'name' => 'Fabrica simple',
                'business_type' => 'factory',
                'description' => 'Cotizacion, nota de venta y despacho obligatorio para controlar entrega y movimiento de inventario.',
                'configuration' => [
                    ...$base,
                    'modules' => [...$base['modules'], 'quotes' => true, 'sales_notes' => true, 'production' => true, 'deliveries' => true, 'inventory' => true],
                    'sales' => [...$base['sales'], 'workflow' => 'quotation_to_sale_note', 'quotation_mode' => 'optional', 'inventory_discount_timing' => 'delivery', 'price_policy' => 'mixed', 'discount_policy' => 'role_limit', 'discount_roles' => ['superadmin', 'administrador'], 'max_discount_percent' => 12, 'credit_limit_policy' => 'block', 'default_credit_limit' => 1500, 'negative_stock_policy' => 'category', 'negative_stock_categories' => ['Materia prima']],
                    'deliveries' => [...$base['deliveries'], 'mode' => 'required', 'driver_required' => true, 'truck_required' => true],
                ],
            ],
            [
                'name' => 'Tienda general',
                'business_type' => 'store',
                'description' => 'Venta directa por ticket con compras e inventario simple.',
                'configuration' => [
                    ...$base,
                    'modules' => [...$base['modules'], 'quotes' => false, 'sales_notes' => true, 'pos' => true, 'deliveries' => false, 'payment_promises' => false],
                    'sales' => [...$base['sales'], 'workflow' => 'pos', 'quotation_mode' => 'disabled', 'document_main' => 'ticket', 'customer_mode' => 'optional', 'price_policy' => 'base_price', 'discount_policy' => 'always_with_limit', 'max_discount_percent' => 3, 'credit_limit_policy' => 'warn', 'default_credit_limit' => 300, 'negative_stock_policy' => 'never'],
                    'deliveries' => [...$base['deliveries'], 'mode' => 'disabled'],
                ],
            ],
        ];
    }
}
