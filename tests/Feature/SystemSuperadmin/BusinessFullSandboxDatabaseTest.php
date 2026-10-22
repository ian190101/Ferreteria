<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Sales\Models\Currency;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleType;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Models\BusinessProfileSandboxSession;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use App\Support\SystemRoles;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\CommittedDatabaseTestCase;

// CREATE/DROP DATABASE confirman implícitamente: estas pruebas requieren datos confirmados.
final class BusinessFullSandboxDatabaseTest extends CommittedDatabaseTestCase
{
    public function test_entra_y_descarta_la_demo_en_una_base_temporal(): void
    {
        $user = $this->scenarioUser(systemSuperadmin: true);

        if (DB::getDriverName() !== 'mysql') {
            $this->actingAs($user)
                ->post(route('system-superadmin.business-profiles.sandbox-full.enter'))
                ->assertRedirect()
                ->assertSessionHas('error', 'La demo completa requiere MySQL o MariaDB para clonar una base aislada.');

            return;
        }

        $this->actingAs($user)
            ->post(route('system-superadmin.business-profiles.sandbox-full.enter'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('business_full_sandbox_id');

        $session = BusinessProfileSandboxSession::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->latest('id')
            ->firstOrFail();

        expect($session->database_name)->not->toBeNull()
            ->and(collect(DB::select("SHOW DATABASES LIKE '{$session->database_name}'"))->isNotEmpty())->toBeTrue();

        $this->actingAs($user)
            ->delete(route('system-superadmin.business-profiles.sandbox-full.discard'))
            ->assertRedirect(route('system-superadmin.business-profiles.index'))
            ->assertSessionMissing('business_full_sandbox_id');

        expect(collect(DB::select("SHOW DATABASES LIKE '{$session->database_name}'"))->isEmpty())->toBeTrue();
    }

    public function test_emite_documentos_en_la_demo_sin_modificar_la_base_principal(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('La demo completa requiere MySQL o MariaDB.');
        }

        $user = $this->scenarioUser(['dashboard.view', 'sales.view', 'sales.manage'], systemSuperadmin: true);
        $this->activeProfile([
            'modules' => [
                'dashboard' => true,
                'sales_notes' => true,
                'quotes' => true,
                'cash' => false,
            ],
            'sales' => [
                'workflow' => 'quotation_to_sale_note',
                'quotation_mode' => 'required',
                'customer_mode' => 'optional',
                'inventory_discount_timing' => 'sale_note',
            ],
            'cash' => [
                'required_to_sell' => false,
            ],
        ]);

        $saleType = SaleType::query()->create(['name' => 'Ocasionales demo', 'is_active' => true]);
        $currency = Currency::query()->create([
            'name' => 'Bolivianos demo',
            'code' => 'BOB-DEMO',
            'symbol' => 'Bs',
            'exchange_rate_to_bob' => 1,
            'is_base' => true,
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'name' => 'Producto cotizacion sandbox',
            'sku' => 'SANDBOX-COT',
            'barcode' => 'SANDBOX-COT-BAR',
            'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
            'base_unit' => 'unidad',
            'allowed_units' => ['unidad'],
            'sale_price' => 12,
            'minimum_stock_meters' => 0,
            'is_active' => true,
        ]);
        ProductBranchStock::query()->create([
            'branch_id' => $user->branch_id,
            'product_id' => $product->id,
            'available_meters' => 20,
            'reserved_meters' => 0,
            'is_enabled' => true,
        ]);

        $this->actingAs($user)
            ->post(route('system-superadmin.business-profiles.sandbox-full.enter'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('business_full_sandbox_id');

        $response = $this->actingAs($user)
            ->post(route('sales.store'), [
                'document_type' => 'quotation',
                'branch_id' => $user->branch_id,
                'sale_type_id' => $saleType->id,
                'currency_id' => $currency->id,
                'advance_mode' => 'none',
                'receipt_number' => 'COT-SANDBOX-001',
                'customer_name' => 'Cliente sandbox',
                'customer_document' => '123',
                'customer_contact' => '70000000',
                'terms' => 'Cotizacion sandbox.',
                'items' => [[
                    'product_id' => $product->id,
                    'product_coil_id' => null,
                    'description' => 'Producto cotizacion sandbox',
                    'unit_label' => 'unidad',
                    'display_quantity' => 2,
                    'display_unit_label' => 'unidad',
                    'calculation_mode' => 'direct',
                    'meters' => 2,
                    'unit_price' => 12,
                    'discount_amount' => 0,
                ]],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $sandboxSale = Sale::query()->where('receipt_number', 'COT-SANDBOX-001')->firstOrFail();

        $this->actingAs($user)
            ->get(route('sales.show', $sandboxSale))
            ->assertOk();

        $noteResponse = $this->actingAs($user)
            ->post(route('sales.store'), [
                'document_type' => 'sale_note',
                'source_quotation_id' => $sandboxSale->id,
                'branch_id' => $user->branch_id,
                'sale_type_id' => $saleType->id,
                'currency_id' => $currency->id,
                'advance_mode' => 'none',
                'receipt_number' => 'NV-SANDBOX-001',
                'customer_name' => 'Cliente sandbox',
                'customer_document' => '123',
                'customer_contact' => '70000000',
                'terms' => 'Nota sandbox.',
                'items' => [[
                    'product_id' => $product->id,
                    'product_coil_id' => null,
                    'description' => 'Producto cotizacion sandbox',
                    'unit_label' => 'unidad',
                    'display_quantity' => 2,
                    'display_unit_label' => 'unidad',
                    'calculation_mode' => 'direct',
                    'meters' => 2,
                    'unit_price' => 12,
                    'discount_amount' => 0,
                ]],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $sandboxNote = Sale::query()->where('receipt_number', 'NV-SANDBOX-001')->firstOrFail();

        expect($noteResponse->headers->get('Location'))->toBe(route('sales.show', $sandboxNote));

        $this->actingAs($user)
            ->get(route('sales.show', $sandboxNote))
            ->assertOk();
    }

    private function scenarioUser(array $permissions = [], bool $systemSuperadmin = false): User
    {
        $branch = Branch::query()->create([
            'name' => 'Sucursal prueba perfil',
            'code' => 'BIZ-'.uniqid(),
            'barcode' => 'BR-BIZ-'.uniqid(),
            'is_active' => true,
        ]);

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $role = Role::firstOrCreate([
            'name' => $systemSuperadmin ? SystemRoles::SYSTEM_SUPERADMIN : 'perfil-negocio-test',
            'guard_name' => 'web',
        ]);

        if ($permissions !== []) {
            $role->syncPermissions($permissions);
        }

        $user = User::factory()->create([
            'branch_id' => $branch->id,
            'email_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function activeProfile(array $overrides): BusinessProfile
    {
        return BusinessProfile::query()->create([
            'name' => 'Perfil activo prueba',
            'business_type' => 'mixed',
            'status' => 'active',
            'configuration' => BusinessProfileConfiguration::normalized($overrides),
            'applied_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        try {
            // La demo cambia la conexión por diseño; la limpieza vuelve al esquema de pruebas.
            config(['database.connections.mysql.database' => 'fabrica_calmina_aroma_testing']);
            DB::purge('mysql');
            if (DB::connection()->getDatabaseName() !== 'fabrica_calmina_aroma_testing' || (string) DB::connection()->getConfig('port') !== '3307') {
                throw new RuntimeException('Se rechazó una limpieza fuera del esquema aislado.');
            }
            foreach (BusinessProfileSandboxSession::query()->whereNotNull('database_name')->pluck('database_name') as $database) {
                if (! preg_match('/^sandbox_[0-9]+_[a-z0-9]{8}$/', $database)) {
                    throw new RuntimeException('Se rechazó una base temporal con nombre inesperado.');
                }
                DB::statement('DROP DATABASE IF EXISTS `'.$database.'`');
            }
        } finally {
            parent::tearDown();
        }
    }
}
