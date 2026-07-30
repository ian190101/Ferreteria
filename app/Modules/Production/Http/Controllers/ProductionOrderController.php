<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Inventory\Services\InventoryQuantityService;
use App\Modules\Production\Http\Requests\StoreProductionOrderRequest;
use App\Modules\Production\Models\ProductionFormula;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderStage;
use App\Support\BranchAccess;
use App\Support\SystemCacheInvalidator;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProductionOrderController extends Controller
{
    public function index(Request $request): Response
    {
        $orders = ProductionOrder::query()
            ->with(['branch:id,name', 'user:id,name', 'formula:id,code,name', 'inputs', 'stages', 'inputProduct:id,name,sku', 'inputCoil:id,barcode,lot_number', 'outputProduct:id,name,sku', 'outputCoil:id,barcode,lot_number'])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('search'), fn ($query) => $query->where('order_number', 'like', '%'.$request->string('search')->toString().'%'))
            ->latest('produced_at')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return Inertia::render('Production/Index', [
            'orders' => $orders,
            'branches' => UiCatalogCache::activeBranchesForUser($request->user()),
            'products' => UiCatalogCache::activeProductsForUser($request->user()),
            'formulas' => ProductionFormula::query()
                ->with(['outputProduct:id,name,sku,base_unit', 'items.inputProduct:id,name,sku,base_unit,purchase_price,inventory_tracking_mode', 'stages'])
                ->where('is_active', true)
                ->where(fn ($query) => $query
                    ->whereNull('branch_id')
                    ->orWhereIn('branch_id', UiCatalogCache::activeBranchesForUser($request->user(), ['id'])->pluck('id')))
                ->orderBy('name')
                ->limit(300)
                ->get(),
            'coils' => ProductCoil::query()
                ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
                ->where('status', 'available')
                ->orderByDesc('id')
                ->limit(500)
                ->get(['id', 'branch_id', 'product_id', 'barcode', 'lot_number', 'available_meters']),
            'filters' => $request->only(['branch_id', 'search', 'per_page']),
        ]);
    }

    public function store(StoreProductionOrderRequest $request, InventoryQuantityService $quantities): RedirectResponse
    {
        DB::transaction(function () use ($request, $quantities) {
            $formula = $request->filled('production_formula_id')
                ? ProductionFormula::query()->with(['items.inputProduct', 'stages', 'outputProduct'])->findOrFail($request->integer('production_formula_id'))
                : null;
            $inputProduct = $formula?->items->first()?->inputProduct ?? Product::query()->findOrFail($request->integer('input_product_id'));
            $outputProduct = $formula?->outputProduct ?? Product::query()->findOrFail($request->integer('output_product_id'));
            $outputMeters = round((float) $request->input('output_meters'), 3);
            $inputMeters = $formula
                ? round((float) $formula->items->first()->quantity * ($outputMeters / max((float) $formula->yield_quantity, 0.0001)), 3)
                : round((float) $request->input('input_meters'), 3);
            $laborCost = round((float) ($request->input('labor_cost') ?? $formula?->standard_labor_cost ?? 0), 4);
            $overheadCost = round((float) ($request->input('overhead_cost') ?? $formula?->standard_overhead_cost ?? 0), 4);
            $inputCost = 0.0;
            $outputCoil = null;

            $order = ProductionOrder::query()->create([
                ...$request->safe()->except(['output_coil_barcode', 'output_lot_number']),
                'user_id' => $request->user()->id,
                'production_formula_id' => $formula?->id,
                'input_product_id' => $inputProduct->id,
                'output_product_id' => $outputProduct->id,
                'input_meters' => $inputMeters,
                'produced_at' => now(),
                'waste_meters' => round((float) $request->input('waste_meters', 0), 3),
                'planned_output_quantity' => $formula ? round((float) $formula->yield_quantity, 4) : $outputMeters,
                'actual_output_quantity' => $outputMeters,
                'labor_cost' => $laborCost,
                'overhead_cost' => $overheadCost,
                'status' => ProductionOrder::STATUS_COMPLETED,
            ]);

            if ($formula) {
                $scale = $outputMeters / max((float) $formula->yield_quantity, 0.0001);
                foreach ($formula->items as $item) {
                    $quantity = round((float) $item->quantity * $scale, 4);
                    $unitCost = (float) ($item->inputProduct->purchase_price ?? 0);
                    $lineCost = round($quantity * $unitCost, 4);
                    $inputCost += $lineCost;

                    $this->consumeInput($order, $item->inputProduct, null, $quantity, $request->user()->id, $quantities, 'production_formula_input_global');
                    $order->inputs()->create([
                        'product_id' => $item->input_product_id,
                        'quantity' => $quantity,
                        'unit_label' => $item->unit_label ?? $item->inputProduct->base_unit,
                        'unit_cost' => $unitCost,
                        'total_cost' => $lineCost,
                        'waste_percentage' => $item->waste_percentage,
                    ]);
                }

                foreach ($formula->stages as $stage) {
                    $order->stages()->create([
                        'name' => $stage->name,
                        'status' => ProductionOrderStage::STATUS_COMPLETED,
                        'started_at' => now(),
                        'completed_at' => now(),
                        'actual_minutes' => $stage->estimated_minutes,
                        'notes' => $stage->description,
                        'sort_order' => $stage->sort_order,
                    ]);
                }
            } else {
                $inputCost = round($inputMeters * (float) ($inputProduct->purchase_price ?? 0), 4);
                $this->consumeInput($order, $inputProduct, $request->integer('input_product_coil_id') ?: null, $inputMeters, $request->user()->id, $quantities);
                $order->inputs()->create([
                    'product_id' => $inputProduct->id,
                    'product_coil_id' => $request->integer('input_product_coil_id') ?: null,
                    'quantity' => $inputMeters,
                    'unit_label' => $inputProduct->base_unit,
                    'unit_cost' => (float) ($inputProduct->purchase_price ?? 0),
                    'total_cost' => $inputCost,
                    'waste_percentage' => 0,
                ]);
            }

            if ($outputProduct->inventory_tracking_mode === Product::TRACKING_COIL) {
                $outputCoil = ProductCoil::query()->create([
                    'branch_id' => $order->branch_id,
                    'product_id' => $outputProduct->id,
                    'barcode' => $request->string('output_coil_barcode')->toString(),
                    'lot_number' => $request->string('output_lot_number')->toString(),
                    'initial_meters' => $outputMeters,
                    'available_meters' => $outputMeters,
                    'status' => 'available',
                ]);

                $order->update(['output_product_coil_id' => $outputCoil->id]);
                $this->movement($order, $outputProduct->id, $outputCoil->id, $request->user()->id, $outputMeters, 0, $outputMeters, 'production_output_coil');
            } else {
                $stock = ProductBranchStock::query()->firstOrCreate([
                    'branch_id' => $order->branch_id,
                    'product_id' => $outputProduct->id,
                ], [
                    'available_meters' => 0,
                    'reserved_meters' => 0,
                ]);

                $stock = ProductBranchStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
                [$before, $after] = $quantities->increase($stock, $outputMeters);

                $this->movement($order, $outputProduct->id, null, $request->user()->id, $outputMeters, $before, $after, 'production_output_global');
            }

            $totalCost = round($inputCost + $laborCost + $overheadCost, 4);
            $order->update([
                'input_cost' => $inputCost,
                'total_cost' => $totalCost,
                'unit_cost' => $outputMeters > 0 ? round($totalCost / $outputMeters, 4) : 0,
            ]);
        });

        SystemCacheInvalidator::bumpOperational();

        return redirect()->route('production.index')->with('success', 'Orden de produccion registrada correctamente.');
    }

    public function storeFormula(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('production.manage'), 403);

        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'output_product_id' => ['required', 'integer', 'exists:products,id'],
            'code' => ['required', 'string', 'max:80', 'unique:production_formulas,code'],
            'name' => ['required', 'string', 'max:160'],
            'yield_quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'expected_waste_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'standard_labor_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'standard_overhead_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'instructions' => ['nullable', 'string', 'max:4000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.input_product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'items.*.unit_label' => ['nullable', 'string', 'max:40'],
            'items.*.waste_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'stages' => ['nullable', 'array'],
            'stages.*.name' => ['required_with:stages', 'string', 'max:120'],
            'stages.*.description' => ['nullable', 'string', 'max:1000'],
            'stages.*.estimated_minutes' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'stages.*.requires_confirmation' => ['boolean'],
        ], [], [
            'branch_id' => 'sucursal',
            'output_product_id' => 'producto terminado',
            'code' => 'codigo de formula',
            'name' => 'nombre de formula',
            'yield_quantity' => 'rendimiento',
            'items' => 'insumos',
            'stages' => 'etapas',
        ]);

        if (! empty($data['branch_id'])) {
            abort_unless(BranchAccess::canAccess($request->user(), (int) $data['branch_id']), 403);
        }

        DB::transaction(function () use ($data) {
            $formula = ProductionFormula::query()->create(collect($data)->except(['items', 'stages'])->all());

            foreach ($data['items'] as $index => $item) {
                $formula->items()->create([
                    ...$item,
                    'waste_percentage' => $item['waste_percentage'] ?? 0,
                    'sort_order' => $index + 1,
                ]);
            }

            foreach ($data['stages'] ?? [] as $index => $stage) {
                $formula->stages()->create([
                    ...$stage,
                    'estimated_minutes' => $stage['estimated_minutes'] ?? 0,
                    'requires_confirmation' => $stage['requires_confirmation'] ?? false,
                    'sort_order' => $index + 1,
                ]);
            }
        });

        SystemCacheInvalidator::bumpOperational();

        return back()->with('success', 'Formula de produccion registrada correctamente.');
    }

    public function updateStage(Request $request, ProductionOrder $order, ProductionOrderStage $stage): RedirectResponse
    {
        abort_unless($stage->production_order_id === $order->id, 404);
        abort_unless(BranchAccess::canAccess($request->user(), (int) $order->branch_id), 403);

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in([ProductionOrderStage::STATUS_PENDING, ProductionOrderStage::STATUS_COMPLETED])],
            'actual_minutes' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'status' => 'estado',
            'actual_minutes' => 'minutos reales',
            'notes' => 'notas',
        ]);

        $stage->update([
            ...$data,
            'completed_at' => $data['status'] === ProductionOrderStage::STATUS_COMPLETED ? now() : null,
            'started_at' => $stage->started_at ?? now(),
        ]);

        SystemCacheInvalidator::bumpOperational();

        return back()->with('success', 'Etapa actualizada correctamente.');
    }

    private function consumeInput(ProductionOrder $order, Product $product, ?int $coilId, float $meters, int $userId, InventoryQuantityService $quantities, string $movementType = 'production_input_global'): void
    {
        if ($product->inventory_tracking_mode === Product::TRACKING_COIL) {
            $coil = ProductCoil::query()->lockForUpdate()->findOrFail($coilId);
            $before = (float) $coil->available_meters;
            $after = round($before - $meters, 3);

            $coil->update([
                'available_meters' => $after,
                'status' => $after <= 0 ? 'depleted' : 'available',
            ]);

            $this->movement($order, $product->id, $coil->id, $userId, -$meters, $before, $after, 'production_input_coil');

            return;
        }

        $stock = ProductBranchStock::query()
            ->where('branch_id', $order->branch_id)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->firstOrFail();

        [$before, $after] = $quantities->decrease($stock, $meters);

            $this->movement($order, $product->id, null, $userId, -$meters, $before, $after, $movementType);
    }

    private function movement(ProductionOrder $order, int $productId, ?int $coilId, int $userId, float $delta, float $before, float $after, string $type): void
    {
        InventoryMovement::query()->create([
            'branch_id' => $order->branch_id,
            'product_id' => $productId,
            'product_coil_id' => $coilId,
            'user_id' => $userId,
            'source_type' => $order::class,
            'source_id' => $order->id,
            'type' => $type,
            'meters_delta' => $delta,
            'meters_before' => $before,
            'meters_after' => $after,
            'reason' => 'Movimiento por produccion',
            'created_at' => now(),
        ]);
    }
}
