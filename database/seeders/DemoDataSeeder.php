<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Branches\Models\BranchSetting;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Thickness;
use App\Modules\Payments\Models\SalePayment;
use App\Modules\Purchases\Models\Purchase;
use App\Modules\Purchases\Models\Supplier;
use App\Modules\Sales\Models\Currency;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $branches = $this->branches();
            $mainBranch = $branches['CENTRAL'];
            $user = $this->user($mainBranch, collect($branches)->pluck('id')->all());
            $currency = Currency::query()->where('code', 'BOB')->firstOrFail();
            $saleType = SaleType::query()->firstOrCreate(['name' => 'Ocasionales'], ['is_active' => true]);
            $customerType = CustomerType::query()->firstOrCreate(['name' => 'Ocasionales'], ['is_active' => true]);

            $units = $this->units();
            $categories = $this->categories($units);
            $thicknesses = $this->thicknesses();

            $products = $this->products($categories, $units, $thicknesses);
            $customers = $this->customers($customerType);
            $suppliers = $this->suppliers();

            foreach (array_values($branches) as $index => $branch) {
                $this->stocks($branch, $products, $index);
                $this->fillMissingStocks($branch, $index);
                $this->coils($branch, $products, $index);
                $this->purchases($branch, $user, $suppliers, $products, $index);
                $this->sales($branch, $user, $currency, $saleType, $customers, $products, $index);
            }
        });

        cache()->forget('ui-catalog:products:id,name,sku,inventory_tracking_mode');
        cache()->forget('ui-catalog:products-with-thickness');
        cache()->forget('ui-catalog:coil-products');
        cache()->forget('ui-catalog:recent-customers:100');
    }

    private function branches(): array
    {
        $rows = [
            'CENTRAL' => ['Sucursal Central', 'BR-CENTRAL', '77300567', '69010531', 'Doble via', 'Av. Doble via la guardia km8 a lado del restaurante los patos', '#2563eb', '#0f172a'],
            'SUC-CBBA01' => ['Sucursal Cochabamba', 'BR-CBBA01', '77441122', '69441122', 'Cochabamba Norte', 'Av. Blanco Galindo km 5, Cochabamba', '#0ea5e9', '#111827'],
            'SUC-LPZ01' => ['Sucursal La Paz', 'BR-LPZ01', '77223344', '69223344', 'El Alto', 'Av. 6 de Marzo, El Alto', '#16a34a', '#172554'],
            'SUC-SCZ02' => ['Sucursal Plan 3000', 'BR-SCZ02', '77665544', '69665544', 'Plan 3000', 'Av. Principal Plan 3000, Santa Cruz', '#dc2626', '#1f2937'],
        ];

        return collect($rows)->mapWithKeys(function (array $row, string $code) {
            $branch = Branch::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $row[0],
                    'barcode' => $row[1],
                    'phone' => $row[2],
                    'secondary_phone' => $row[3],
                    'point_of_sale_name' => $row[4],
                    'address' => $row[5],
                    'is_active' => true,
                ],
            );

            BranchSetting::query()->updateOrCreate(
                ['branch_id' => $branch->id],
                [
                    'primary_color' => $row[6],
                    'secondary_color' => $row[7],
                    'theme_mode' => 'system',
                ],
            );

            return [$code => $branch];
        })->all();
    }

    private function user(Branch $branch, array $branchIds): User
    {
        $user = User::query()->where('email', 'admin@calmina.local')->first()
            ?? User::query()->first()
            ?? User::query()->create([
                'branch_id' => $branch->id,
                'name' => 'Administrador Demo',
                'email' => 'admin@calmina.local',
                'password' => Hash::make('admin12345'),
                'is_active' => true,
            ]);

        if (method_exists($user, 'accessibleBranches')) {
            $user->accessibleBranches()->syncWithoutDetaching($branchIds);
        }

        $role = Role::query()->where('name', 'superadmin')->first();

        if ($role && ! $user->hasRole($role->name)) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function units(): array
    {
        return [
            'metro' => ProductUnit::query()->firstOrCreate(['symbol' => 'm'], ['name' => 'Metro', 'kind' => 'longitud', 'is_active' => true]),
            'unidad' => ProductUnit::query()->firstOrCreate(['symbol' => 'u'], ['name' => 'Unidad', 'kind' => 'cantidad', 'is_active' => true]),
            'bolsa' => ProductUnit::query()->firstOrCreate(['symbol' => 'bolsa'], ['name' => 'Bolsa', 'kind' => 'cantidad', 'is_active' => true]),
            'litro' => ProductUnit::query()->firstOrCreate(['symbol' => 'l'], ['name' => 'Litro', 'kind' => 'volumen', 'is_active' => true]),
            'kilo' => ProductUnit::query()->firstOrCreate(['symbol' => 'kg'], ['name' => 'Kilogramo', 'kind' => 'peso', 'is_active' => true]),
        ];
    }

    private function categories(array $units): array
    {
        $data = [
            'calaminas' => ['Calaminas', $units['metro']->id, Product::TRACKING_COIL, true],
            'perfiles' => ['Perfiles metalicos', $units['metro']->id, Product::TRACKING_GLOBAL, false],
            'tornilleria' => ['Tornilleria y fijaciones', $units['unidad']->id, Product::TRACKING_GLOBAL, false],
            'cementos' => ['Cementos y morteros', $units['bolsa']->id, Product::TRACKING_GLOBAL, false],
            'pinturas' => ['Pinturas y selladores', $units['litro']->id, Product::TRACKING_GLOBAL, false],
            'herramientas' => ['Herramientas', $units['unidad']->id, Product::TRACKING_GLOBAL, false],
        ];

        return collect($data)
            ->mapWithKeys(fn (array $item, string $key) => [$key => ProductCategory::query()->firstOrCreate(
                ['name' => $item[0]],
                [
                    'default_unit_id' => $item[1],
                    'default_tracking_mode' => $item[2],
                    'requires_thickness' => $item[3],
                    'is_active' => true,
                ],
            )])
            ->all();
    }

    private function thicknesses(): array
    {
        return [
            '0.35' => Thickness::query()->updateOrCreate(
                ['millimeters' => 0.3500],
                ['name' => '0.35 mm', 'kg_per_meter' => 2.750000, 'kg_to_meter_factor' => round(1 / 2.75, 6), 'is_active' => true],
            ),
            '0.40' => Thickness::query()->updateOrCreate(
                ['millimeters' => 0.4000],
                ['name' => '0.40 mm', 'kg_per_meter' => 3.130000, 'kg_to_meter_factor' => round(1 / 3.13, 6), 'is_active' => true],
            ),
            '0.50' => Thickness::query()->updateOrCreate(
                ['millimeters' => 0.5000],
                ['name' => '0.50 mm', 'kg_per_meter' => 3.850000, 'kg_to_meter_factor' => round(1 / 3.85, 6), 'is_active' => true],
            ),
        ];
    }

    private function products(array $categories, array $units, array $thicknesses): array
    {
        $rows = [
            ['sku' => 'CAL-035-ZINC', 'barcode' => '775000100001', 'name' => 'Calamina zinc 0.35 mm', 'category' => 'calaminas', 'unit' => 'metro', 'tracking' => Product::TRACKING_COIL, 'thickness' => '0.35', 'min' => 250, 'attributes' => ['color' => 'Zinc/Plateado', 'ancho' => '1.00 m']],
            ['sku' => 'CAL-040-ROJO', 'barcode' => '775000100002', 'name' => 'Calamina roja 0.40 mm', 'category' => 'calaminas', 'unit' => 'metro', 'tracking' => Product::TRACKING_COIL, 'thickness' => '0.40', 'min' => 300, 'attributes' => ['color' => 'Rojo', 'ancho' => '1.00 m']],
            ['sku' => 'CAL-050-AZUL', 'barcode' => '775000100003', 'name' => 'Calamina azul 0.50 mm', 'category' => 'calaminas', 'unit' => 'metro', 'tracking' => Product::TRACKING_COIL, 'thickness' => '0.50', 'min' => 200, 'attributes' => ['color' => 'Azul', 'ancho' => '1.00 m']],
            ['sku' => 'PER-C-80X40', 'barcode' => '775000200001', 'name' => 'Perfil C galvanizado 80x40', 'category' => 'perfiles', 'unit' => 'metro', 'tracking' => Product::TRACKING_GLOBAL, 'thickness' => null, 'min' => 120, 'attributes' => ['medida' => '80x40', 'material' => 'Galvanizado']],
            ['sku' => 'ANG-1-8', 'barcode' => '775000200002', 'name' => 'Angulo metalico 1/8', 'category' => 'perfiles', 'unit' => 'metro', 'tracking' => Product::TRACKING_GLOBAL, 'thickness' => null, 'min' => 80, 'attributes' => ['medida' => '1/8', 'material' => 'Acero']],
            ['sku' => 'TOR-TEK-1', 'barcode' => '775000300001', 'name' => 'Tornillo autoperforante 1 pulgada', 'category' => 'tornilleria', 'unit' => 'unidad', 'tracking' => Product::TRACKING_GLOBAL, 'thickness' => null, 'min' => 500, 'attributes' => ['medida' => '1 pulgada', 'tipo' => 'Autoperforante']],
            ['sku' => 'TOR-TEK-2', 'barcode' => '775000300002', 'name' => 'Tornillo autoperforante 2 pulgadas', 'category' => 'tornilleria', 'unit' => 'unidad', 'tracking' => Product::TRACKING_GLOBAL, 'thickness' => null, 'min' => 500, 'attributes' => ['medida' => '2 pulgadas', 'tipo' => 'Autoperforante']],
            ['sku' => 'CEM-50KG', 'barcode' => '775000400001', 'name' => 'Cemento IP-30 bolsa 50 kg', 'category' => 'cementos', 'unit' => 'bolsa', 'tracking' => Product::TRACKING_GLOBAL, 'thickness' => null, 'min' => 25, 'attributes' => ['peso' => '50 kg']],
            ['sku' => 'PIN-ANT-4L', 'barcode' => '775000500001', 'name' => 'Pintura anticorrosiva roja 4 L', 'category' => 'pinturas', 'unit' => 'litro', 'tracking' => Product::TRACKING_GLOBAL, 'thickness' => null, 'min' => 20, 'attributes' => ['color' => 'Rojo', 'presentacion' => '4 L']],
            ['sku' => 'SIL-TRANS', 'barcode' => '775000500002', 'name' => 'Silicona transparente', 'category' => 'pinturas', 'unit' => 'unidad', 'tracking' => Product::TRACKING_GLOBAL, 'thickness' => null, 'min' => 30, 'attributes' => ['color' => 'Transparente']],
            ['sku' => 'HERR-TAL-650', 'barcode' => '775000600001', 'name' => 'Taladro percutor 650 W', 'category' => 'herramientas', 'unit' => 'unidad', 'tracking' => Product::TRACKING_GLOBAL, 'thickness' => null, 'min' => 3, 'attributes' => ['potencia' => '650 W']],
            ['sku' => 'HERR-REM-MAN', 'barcode' => '775000600002', 'name' => 'Remachadora manual', 'category' => 'herramientas', 'unit' => 'unidad', 'tracking' => Product::TRACKING_GLOBAL, 'thickness' => null, 'min' => 5, 'attributes' => ['tipo' => 'Manual']],
            ['sku' => 'CLAV-2P', 'barcode' => '775000300003', 'name' => 'Clavo comun 2 pulgadas', 'category' => 'tornilleria', 'unit' => 'kilo', 'tracking' => Product::TRACKING_GLOBAL, 'thickness' => null, 'min' => 40, 'attributes' => ['medida' => '2 pulgadas', 'tipo' => 'Comun']],
            ['sku' => 'ALAM-REC-16', 'barcode' => '775000300004', 'name' => 'Alambre recocido numero 16', 'category' => 'perfiles', 'unit' => 'kilo', 'tracking' => Product::TRACKING_GLOBAL, 'thickness' => null, 'min' => 60, 'attributes' => ['calibre' => '16', 'tipo' => 'Recocido']],
            ['sku' => 'DIS-CORTE-7', 'barcode' => '775000600003', 'name' => 'Disco de corte metal 7 pulgadas', 'category' => 'herramientas', 'unit' => 'unidad', 'tracking' => Product::TRACKING_GLOBAL, 'thickness' => null, 'min' => 20, 'attributes' => ['diametro' => '7 pulgadas']],
            ['sku' => 'ELECT-6013', 'barcode' => '775000600004', 'name' => 'Electrodo 6013 1/8', 'category' => 'herramientas', 'unit' => 'kilo', 'tracking' => Product::TRACKING_GLOBAL, 'thickness' => null, 'min' => 35, 'attributes' => ['tipo' => '6013', 'medida' => '1/8']],
            ['sku' => 'TUB-PVC-1-2', 'barcode' => '775000700001', 'name' => 'Tubo PVC 1/2 pulgada', 'category' => 'perfiles', 'unit' => 'metro', 'tracking' => Product::TRACKING_GLOBAL, 'thickness' => null, 'min' => 100, 'attributes' => ['material' => 'PVC', 'medida' => '1/2 pulgada']],
            ['sku' => 'CODO-PVC-1-2', 'barcode' => '775000700002', 'name' => 'Codo PVC 1/2 pulgada', 'category' => 'tornilleria', 'unit' => 'unidad', 'tracking' => Product::TRACKING_GLOBAL, 'thickness' => null, 'min' => 50, 'attributes' => ['material' => 'PVC', 'medida' => '1/2 pulgada']],
            ['sku' => 'CARRET-65L', 'barcode' => '775000600005', 'name' => 'Carretilla metalica 65 L', 'category' => 'herramientas', 'unit' => 'unidad', 'tracking' => Product::TRACKING_GLOBAL, 'thickness' => null, 'min' => 2, 'attributes' => ['capacidad' => '65 L']],
            ['sku' => 'CASCO-SEG', 'barcode' => '775000600006', 'name' => 'Casco de seguridad amarillo', 'category' => 'herramientas', 'unit' => 'unidad', 'tracking' => Product::TRACKING_GLOBAL, 'thickness' => null, 'min' => 12, 'attributes' => ['color' => 'Amarillo']],
        ];

        return collect($rows)->mapWithKeys(function (array $row) use ($categories, $units, $thicknesses) {
            $product = Product::query()->updateOrCreate(
                ['sku' => $row['sku']],
                [
                    'thickness_id' => $row['thickness'] ? $thicknesses[$row['thickness']]->id : null,
                    'product_category_id' => $categories[$row['category']]->id,
                    'product_unit_id' => $units[$row['unit']]->id,
                    'name' => $row['name'],
                    'category' => $categories[$row['category']]->name,
                    'barcode' => $row['barcode'],
                    'inventory_tracking_mode' => $row['tracking'],
                    'base_unit' => $units[$row['unit']]->symbol,
                    'attributes' => $row['attributes'],
                    'default_width' => $row['category'] === 'calaminas' ? 1 : null,
                    'minimum_stock_meters' => $row['min'],
                    'is_active' => true,
                ],
            );

            return [$row['sku'] => $product];
        })->all();
    }

    private function stocks(Branch $branch, array $products, int $branchIndex = 0): void
    {
        $factor = 1 + ($branchIndex * 0.18);
        $stocks = [
            'CAL-035-ZINC' => 1780,
            'CAL-040-ROJO' => 1320,
            'CAL-050-AZUL' => 860,
            'PER-C-80X40' => 420,
            'ANG-1-8' => 260,
            'TOR-TEK-1' => 3500,
            'TOR-TEK-2' => 2400,
            'CEM-50KG' => 80,
            'PIN-ANT-4L' => 48,
            'SIL-TRANS' => 75,
            'HERR-TAL-650' => 8,
            'HERR-REM-MAN' => 14,
            'CLAV-2P' => 120,
            'ALAM-REC-16' => 180,
            'DIS-CORTE-7' => 90,
            'ELECT-6013' => 140,
            'TUB-PVC-1-2' => 520,
            'CODO-PVC-1-2' => 260,
            'CARRET-65L' => 10,
            'CASCO-SEG' => 65,
        ];

        foreach ($stocks as $sku => $available) {
            ProductBranchStock::query()->updateOrCreate(
                ['branch_id' => $branch->id, 'product_id' => $products[$sku]->id],
                ['available_meters' => round($available * $factor, 3), 'reserved_meters' => 0],
            );
        }
    }

    private function fillMissingStocks(Branch $branch, int $branchIndex = 0): void
    {
        Product::query()
            ->where('is_active', true)
            ->select(['id', 'minimum_stock_meters'])
            ->chunkById(100, function ($products) use ($branch, $branchIndex) {
                foreach ($products as $product) {
                    ProductBranchStock::query()->firstOrCreate(
                        ['branch_id' => $branch->id, 'product_id' => $product->id],
                        [
                            'available_meters' => max((float) $product->minimum_stock_meters * (2 + $branchIndex), 20),
                            'reserved_meters' => 0,
                        ],
                    );
                }
            });
    }

    private function coils(Branch $branch, array $products, int $branchIndex = 0): void
    {
        $code = str_replace(['SUC-', '-'], '', $branch->code);
        $factor = 1 + ($branchIndex * 0.1);
        $coils = [
            ['sku' => 'CAL-035-ZINC', 'barcode' => "BOB-{$code}-ZIN-035-001", 'lot' => "L-{$code}-ZIN-035-A", 'kg' => round(4000 * $factor, 3), 'kgm' => 2.75],
            ['sku' => 'CAL-035-ZINC', 'barcode' => "BOB-{$code}-ZIN-035-002", 'lot' => "L-{$code}-ZIN-035-B", 'kg' => round(2500 * $factor, 3), 'kgm' => 2.75],
            ['sku' => 'CAL-040-ROJO', 'barcode' => "BOB-{$code}-ROJ-040-001", 'lot' => "L-{$code}-ROJ-040-A", 'kg' => round(4000 * $factor, 3), 'kgm' => 3.13],
            ['sku' => 'CAL-040-ROJO', 'barcode' => "BOB-{$code}-ROJ-040-002", 'lot' => "L-{$code}-ROJ-040-B", 'kg' => round(2200 * $factor, 3), 'kgm' => 3.13],
            ['sku' => 'CAL-050-AZUL', 'barcode' => "BOB-{$code}-AZU-050-001", 'lot' => "L-{$code}-AZU-050-A", 'kg' => round(3000 * $factor, 3), 'kgm' => 3.85],
        ];

        foreach ($coils as $coil) {
            $meters = round($coil['kg'] / $coil['kgm'], 3);

            ProductCoil::query()->updateOrCreate(
                ['barcode' => $coil['barcode']],
                [
                    'branch_id' => $branch->id,
                    'product_id' => $products[$coil['sku']]->id,
                    'lot_number' => $coil['lot'],
                    'initial_meters' => $meters,
                    'available_meters' => $meters,
                    'initial_kg' => $coil['kg'],
                    'status' => 'available',
                ],
            );
        }
    }

    private function customers(CustomerType $type): array
    {
        $rows = [
            ['85911', 'Camacho Ruben', '70775320', 'Av. Doble via km 8'],
            ['102030', 'Constructora Los Pinos', '72104587', 'Zona Equipetrol'],
            ['204050', 'Ferreteria San Jorge', '69011223', 'Mercado Mutualista'],
            ['309988', 'Alvarez Maria', '76543210', 'Barrio El Bajio'],
            ['401122', 'Taller Metalico Robles', '70011220', 'Villa Primero de Mayo'],
            ['502233', 'Constructora Rio Norte', '72004560', 'Av. Banzer 6to anillo'],
            ['603344', 'Ferreteria La Economica', '67890011', 'Zona Los Lotes'],
            ['704455', 'Mendez Patricia', '71004567', 'Barrio Sirari'],
            ['805566', 'Obras Civiles Delta', '72661234', 'Parque Industrial'],
            ['906677', 'Comercial El Constructor', '76332110', 'Mercado Abasto'],
        ];

        return collect($rows)->mapWithKeys(fn (array $row) => [$row[0] => Customer::query()->updateOrCreate(
            ['document_number' => $row[0]],
            [
                'customer_type_id' => $type->id,
                'name' => $row[1],
                'phone' => $row[2],
                'email' => null,
                'address' => $row[3],
                'is_active' => true,
            ],
        )])->all();
    }

    private function suppliers(): array
    {
        $rows = [
            ['ACEROS-ANDINOS', 'Aceros Andinos SRL', '78001234', '1020304012'],
            ['PINTURAS-ORIENTE', 'Pinturas Oriente', '75550123', '2040608011'],
            ['FERRO-IMPORT', 'Ferro Import Bolivia', '69006789', '9080706011'],
        ];

        return collect($rows)->mapWithKeys(fn (array $row) => [$row[0] => Supplier::query()->updateOrCreate(
            ['tax_id' => $row[3]],
            ['name' => $row[1], 'phone' => $row[2], 'email' => null, 'is_active' => true],
        )])->all();
    }

    private function purchases(Branch $branch, User $user, array $suppliers, array $products, int $branchIndex = 0): void
    {
        $documentNumber = 'COMP-DEMO-'.$branch->code.'-0001';
        $total = 18450 + ($branchIndex * 2750);
        $paid = 8000 + ($branchIndex * 900);

        $purchase = Purchase::query()->firstOrCreate(
            ['document_number' => $documentNumber],
            [
                'branch_id' => $branch->id,
                'supplier_id' => $suppliers['ACEROS-ANDINOS']->id,
                'user_id' => $user->id,
                'purchase_date' => now()->subDays(3 + $branchIndex)->toDateString(),
                'total_amount' => $total,
                'paid_amount' => $paid,
                'balance_due' => $total - $paid,
                'payment_status' => 'partial_paid',
                'status' => 'received',
            ],
        );

        if ($purchase->items()->count() === 0) {
            $purchase->items()->createMany([
                ['product_id' => $products['CAL-040-ROJO']->id, 'product_coil_id' => null, 'coil_barcode' => null, 'kilograms' => 4000, 'meters' => round(4000 / 3.13, 3), 'unit_cost' => 8.5, 'conversion_factor' => round(1 / 3.13, 6), 'lot_number' => 'L-ROJ-040-A', 'description' => 'Ingreso bobina roja 0.40 mm'],
                ['product_id' => $products['TOR-TEK-1']->id, 'product_coil_id' => null, 'coil_barcode' => null, 'kilograms' => null, 'meters' => 2000, 'unit_cost' => 0.18, 'conversion_factor' => null, 'lot_number' => null, 'description' => 'Tornillo autoperforante 1 pulgada'],
                ['product_id' => $products['CEM-50KG']->id, 'product_coil_id' => null, 'coil_barcode' => null, 'kilograms' => null, 'meters' => 50, 'unit_cost' => 56, 'conversion_factor' => null, 'lot_number' => null, 'description' => 'Cemento IP-30 bolsa 50 kg'],
            ]);
        }
    }

    private function sales(Branch $branch, User $user, Currency $currency, SaleType $saleType, array $customers, array $products, int $branchIndex = 0): void
    {
        $saleTotal = 4940 + ($branchIndex * 450);
        $paymentAmount = 3000 + ($branchIndex * 250);
        $receiptNumber = 'NV-DEMO-'.$branch->code.'-000001';

        $sale = Sale::query()->firstOrCreate(
            ['receipt_number' => $receiptNumber],
            [
                'branch_id' => $branch->id,
                'user_id' => $user->id,
                'sale_type_id' => $saleType->id,
                'currency_id' => $currency->id,
                'customer_id' => $customers['85911']->id,
                'advance_option_id' => null,
                'document_type' => 'sale_note',
                'customer_name' => $customers['85911']->name,
                'customer_document' => $customers['85911']->document_number,
                'customer_contact' => $customers['85911']->phone,
                'sold_at' => now()->subDays(1 + $branchIndex),
                'exchange_rate_to_bob' => 1,
                'subtotal' => $saleTotal + 50,
                'discount_total' => 50,
                'advance_percentage' => 0,
                'advance_amount' => 0,
                'balance_due' => $saleTotal - $paymentAmount,
                'total' => $saleTotal,
                'status' => 'partial_paid',
                'terms' => 'Entrega en sucursal.',
                'internal_notes' => 'Venta de prueba.',
            ],
        );

        if ($sale->items()->count() === 0) {
            $sale->items()->createMany([
                ['product_id' => $products['CAL-040-ROJO']->id, 'product_coil_id' => null, 'description' => 'Calamina roja 0.40 mm', 'unit_label' => 'm', 'meters' => 120, 'unit_price' => 28, 'discount_amount' => 50, 'total' => 3310],
                ['product_id' => $products['TOR-TEK-1']->id, 'product_coil_id' => null, 'description' => 'Tornillo autoperforante 1 pulgada', 'unit_label' => 'u', 'meters' => 800, 'unit_price' => 0.35, 'discount_amount' => 0, 'total' => 280],
                ['product_id' => $products['CEM-50KG']->id, 'product_coil_id' => null, 'description' => 'Cemento IP-30 bolsa 50 kg', 'unit_label' => 'bolsa', 'meters' => 25, 'unit_price' => 54, 'discount_amount' => 0, 'total' => 1350],
            ]);
        }

        SalePayment::query()->firstOrCreate(
            ['sale_id' => $sale->id, 'amount' => $paymentAmount],
            [
                'branch_id' => $branch->id,
                'user_id' => $user->id,
                'payment_method_id' => DB::table('payment_methods')->where('code', 'cash')->value('id'),
                'paid_at' => now()->subDays(1 + $branchIndex),
                'exchange_rate_to_bob' => 1,
                'amount_bob' => $paymentAmount,
                'reference' => null,
                'notes' => 'Pago inicial de prueba.',
            ],
        );

        $quotationTotal = 2150 + ($branchIndex * 380);
        $quotationNumber = 'COT-DEMO-'.$branch->code.'-000001';

        $quotation = Sale::query()->firstOrCreate(
            ['receipt_number' => $quotationNumber],
            [
                'branch_id' => $branch->id,
                'user_id' => $user->id,
                'sale_type_id' => $saleType->id,
                'currency_id' => $currency->id,
                'customer_id' => $customers['102030']->id,
                'advance_option_id' => null,
                'document_type' => 'quotation',
                'customer_name' => $customers['102030']->name,
                'customer_document' => $customers['102030']->document_number,
                'customer_contact' => $customers['102030']->phone,
                'sold_at' => now()->subHours($branchIndex * 3),
                'exchange_rate_to_bob' => 1,
                'subtotal' => $quotationTotal,
                'discount_total' => 0,
                'advance_percentage' => 30,
                'advance_amount' => round($quotationTotal * 0.3, 2),
                'balance_due' => round($quotationTotal * 0.7, 2),
                'total' => $quotationTotal,
                'status' => 'quoted',
                'terms' => 'Validez de oferta 7 dias.',
                'internal_notes' => 'Cotizacion de prueba.',
            ],
        );

        if ($quotation->items()->count() === 0) {
            $quotation->items()->createMany([
                ['product_id' => $products['PER-C-80X40']->id, 'product_coil_id' => null, 'description' => 'Perfil C galvanizado 80x40', 'unit_label' => 'm', 'meters' => 100, 'unit_price' => 18.5, 'discount_amount' => 0, 'total' => 1850],
                ['product_id' => $products['SIL-TRANS']->id, 'product_coil_id' => null, 'description' => 'Silicona transparente', 'unit_label' => 'u', 'meters' => 10, 'unit_price' => 30, 'discount_amount' => 0, 'total' => 300],
            ]);
        }
    }
}
