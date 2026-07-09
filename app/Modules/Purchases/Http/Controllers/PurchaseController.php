<?php

namespace App\Modules\Purchases\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Purchases\Http\Requests\StorePurchaseRequest;
use App\Modules\Purchases\Models\Purchase;
use App\Support\BranchAccess;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseController extends Controller
{
    public function index(Request $request): Response
    {
        $purchases = Purchase::query()
            ->select([
                'id',
                'branch_id',
                'supplier_id',
                'user_id',
                'document_number',
                'purchase_date',
                'total_amount',
                'paid_amount',
                'balance_due',
                'payment_status',
                'status',
            ])
            ->with(['branch:id,name', 'supplier:id,name', 'user:id,name'])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request) {
                $search = $request->string('search')->toString();

                $query->where('document_number', 'like', "%{$search}%");
            })
            ->latest('purchase_date')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return Inertia::render('Purchases/Index', [
            'purchases' => $purchases,
            'filters' => $request->only('per_page'),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Purchases/Form', [
            'branches' => UiCatalogCache::activeBranchesForUser($request->user()),
            'units' => UiCatalogCache::productUnits(),
            'suppliers' => Inertia::defer(fn () => UiCatalogCache::activeSuppliers(), 'purchase-form-catalogs'),
            'products' => Inertia::defer(fn () => UiCatalogCache::activeProductsWithThickness(), 'purchase-form-catalogs'),
        ]);
    }

    public function store(StorePurchaseRequest $request): RedirectResponse
    {
        $purchase = DB::transaction(function () use ($request) {
            $validatedItems = collect($request->validated('items'));
            $products = Product::query()
                ->with(['thickness', 'unit:id,symbol', 'productCategory.attributes.unit:id,symbol'])
                ->whereIn('id', $validatedItems->pluck('product_id')->unique()->values())
                ->get(['id', 'thickness_id', 'product_category_id', 'product_unit_id', 'name', 'base_unit', 'inventory_tracking_mode', 'attributes'])
                ->keyBy('id');

            $items = $validatedItems->map(function (array $item) use ($products) {
                $product = $products->get($item['product_id']);
                $kilograms = $this->normalizeKilograms($item);
                $meters = filled($item['meters'] ?? null) ? (float) $item['meters'] : null;
                $kgPerMeter = $product->thickness?->kg_per_meter;
                $conversionFactor = $kgPerMeter ? round(1 / (float) $kgPerMeter, 6) : $product->thickness?->kg_to_meter_factor;

                if (! $meters && $kilograms && $kgPerMeter) {
                    $meters = round($kilograms / (float) $kgPerMeter, 3);
                } elseif (! $meters && $kilograms && $conversionFactor) {
                    $meters = round($kilograms * (float) $conversionFactor, 3);
                }

                $item['meters'] = $meters;
                $item['kilograms'] = $kilograms;
                $item['conversion_factor'] = $conversionFactor;
                $item['display_quantity'] = $item['display_quantity'] ?? $meters ?? 1;
                $item['display_unit_label'] = $item['display_unit_label'] ?? $product->unit?->symbol ?? $product->base_unit ?? 'unidad';
                $item['item_attributes'] = $this->purchaseItemAttributes($product, $item['item_attributes'] ?? []);
                $item['calculation_mode'] = $item['calculation_mode'] ?? 'direct';
                $item['description'] = $item['description'] ?: $product->name;
                $item['line_total'] = round($meters * (float) $item['unit_cost'], 2);

                return $item;
            });

            $purchase = Purchase::query()->create([
                'branch_id' => $request->integer('branch_id'),
                'supplier_id' => $request->input('supplier_id'),
                'user_id' => $request->user()->id,
                'document_number' => $request->string('document_number')->toString(),
                'purchase_date' => now()->toDateString(),
                'total_amount' => $items->sum('line_total'),
                'paid_amount' => 0,
                'balance_due' => $items->sum('line_total'),
                'payment_status' => $items->sum('line_total') > 0 ? 'unpaid' : 'paid',
                'status' => $request->string('status')->toString(),
            ]);

            foreach ($items as $item) {
                $product = $products->get($item['product_id']);
                $coil = null;

                if ($request->string('status')->toString() === 'received') {
                    if ($product->inventory_tracking_mode === Product::TRACKING_COIL) {
                        $coil = ProductCoil::query()->create([
                            'branch_id' => $purchase->branch_id,
                            'product_id' => $product->id,
                            'barcode' => $item['coil_barcode'],
                            'lot_number' => $item['lot_number'],
                            'initial_meters' => $item['meters'],
                            'available_meters' => $item['meters'],
                            'initial_kg' => $item['kilograms'],
                            'status' => 'available',
                        ]);
                    } else {
                        $stock = ProductBranchStock::query()->firstOrCreate([
                            'branch_id' => $purchase->branch_id,
                            'product_id' => $product->id,
                        ], [
                            'available_meters' => 0,
                            'reserved_meters' => 0,
                        ]);

                        $stock = ProductBranchStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
                        $before = (float) $stock->available_meters;
                        $after = round($before + (float) $item['meters'], 3);
                        $stock->update(['available_meters' => $after]);

                        $this->movement($purchase, $product->id, null, $request->user()->id, $item['meters'], $before, $after, 'purchase_entry_global');
                    }
                }

                $purchaseItem = $purchase->items()->create([
                    'product_id' => $product->id,
                    'product_coil_id' => $coil?->id,
                    'coil_barcode' => $item['coil_barcode'] ?? null,
                    'display_quantity' => $item['display_quantity'],
                    'display_unit_label' => $item['display_unit_label'],
                    'item_attributes' => $item['item_attributes'],
                    'calculation_mode' => $item['calculation_mode'],
                    'kilograms' => $item['kilograms'],
                    'meters' => $item['meters'],
                    'unit_cost' => $item['unit_cost'],
                    'conversion_factor' => $item['conversion_factor'],
                    'lot_number' => $item['lot_number'] ?? null,
                    'description' => $item['description'],
                ]);

                if ($coil) {
                    $this->movement($purchaseItem, $product->id, $coil->id, $request->user()->id, $item['meters'], 0, (float) $coil->available_meters, 'purchase_entry_coil');
                }
            }

            return $purchase;
        });

        return redirect()->route('purchases.show', $purchase)->with('success', 'Compra registrada correctamente.');
    }

    public function show(Purchase $purchase): Response
    {
        abort_unless(BranchAccess::canAccess(request()->user(), (int) $purchase->branch_id), 403);

        return Inertia::render('Purchases/Show', [
            'purchase' => $purchase->load(['branch:id,name', 'supplier:id,name', 'user:id,name', 'items.product:id,name,sku,inventory_tracking_mode', 'items.coil:id,barcode,lot_number,available_meters']),
        ]);
    }

    private function movement($source, int $productId, ?int $coilId, int $userId, float $delta, float $before, float $after, string $type): void
    {
        $branchId = $source instanceof Purchase
            ? $source->branch_id
            : $source->purchase()->value('branch_id');

        InventoryMovement::query()->create([
            'branch_id' => $branchId,
            'product_id' => $productId,
            'product_coil_id' => $coilId,
            'user_id' => $userId,
            'source_type' => $source::class,
            'source_id' => $source->id,
            'type' => $type,
            'meters_delta' => $delta,
            'meters_before' => $before,
            'meters_after' => $after,
            'reason' => 'Ingreso por compra',
            'created_at' => now(),
        ]);
    }

    private function normalizeKilograms(array $item): ?float
    {
        if (blank($item['kilograms'] ?? null)) {
            return null;
        }

        $kilograms = (float) $item['kilograms'];

        return ($item['weight_unit'] ?? 'kg') === 'ton'
            ? round($kilograms * 1000, 3)
            : $kilograms;
    }

    private function purchaseItemAttributes(Product $product, array $payloadAttributes): array
    {
        $payloadByCode = collect($payloadAttributes)->keyBy('code');

        return $product->productCategory?->attributes
            ->map(function ($definition) use ($product, $payloadByCode) {
                $payload = $payloadByCode->get($definition->code, []);
                $defaultValue = data_get($product->attributes ?? [], $definition->code);
                $value = data_get($payload, 'value', $defaultValue);

                return [
                    'code' => $definition->code,
                    'name' => $definition->name,
                    'value' => blank($value) ? '-' : (string) $value,
                    'unit' => $definition->unit?->symbol,
                ];
            })
            ->values()
            ->all() ?? [];
    }
}
