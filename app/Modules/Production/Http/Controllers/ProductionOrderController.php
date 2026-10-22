<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Inventory\Services\InventoryQuantityService;
use App\Modules\Finance\Support\Decimal;
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
            ->paginate(max(1, min(100, $request->integer('per_page', 15))))
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
            $outputMeters = Decimal::round((string) $request->input('output_meters'), 3);
            if (bccomp($outputMeters, '0', 3) <= 0) {
                throw \Illuminate\Validation\ValidationException::withMessages(['output_meters' => 'La producción debe ser mayor que cero.']);
            }
            if ($formula && bccomp((string) $formula->yield_quantity, '0', 4) <= 0) {
                throw \Illuminate\Validation\ValidationException::withMessages(['production_formula_id' => 'El rendimiento de la fórmula debe ser mayor que cero.']);
            }
            $inputMeters = $formula
                ? Decimal::round(bcdiv(bcmul((string) $formula->items->first()->quantity, $outputMeters, 12), (string) $formula->yield_quantity, 12), 3)
                : Decimal::round((string) $request->input('input_meters'), 3);
            $laborCost = Decimal::round((string) ($request->input('labor_cost') ?? $formula?->standard_labor_cost ?? 0), 4);
            $overheadCost = Decimal::round((string) ($request->input('overhead_cost') ?? $formula?->standard_overhead_cost ?? 0), 4);
            $inputCost = '0.0000';
            $outputCoil = null;

            if ($formula && $formula->items->contains(fn ($item) => $item->inputProduct->inventory_tracking_mode === Product::TRACKING_COIL)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'production_formula_id' => 'La fórmula requiere seleccionar bobinas; usa producción manual por bobina',
                ]);
            }

            $inputCoilId = ! $formula && $inputProduct->inventory_tracking_mode === Product::TRACKING_COIL
                ? ($request->integer('input_product_coil_id') ?: null)
                : null;

            $order = ProductionOrder::query()->create([
                ...$request->safe()->except(['output_coil_barcode', 'output_lot_number', 'input_product_coil_id']),
                'user_id' => $request->user()->id,
                'production_formula_id' => $formula?->id,
                'input_product_id' => $inputProduct->id,
                'input_product_coil_id' => $inputCoilId,
                'output_product_id' => $outputProduct->id,
                'input_meters' => $inputMeters,
                'produced_at' => now(),
                'waste_meters' => round((float) $request->input('waste_meters', 0), 3),
                'planned_output_quantity' => $formula ? Decimal::round((string) $formula->yield_quantity, 4) : $outputMeters,
                'actual_output_quantity' => $outputMeters,
                'labor_cost' => $laborCost,
                'overhead_cost' => $overheadCost,
                'status' => ProductionOrder::STATUS_COMPLETED,
            ]);

            if ($formula) {
                foreach ($formula->items as $item) {
                    $quantity = Decimal::round(
                        bcdiv(bcmul((string) $item->quantity, $outputMeters, 12), (string) $formula->yield_quantity, 12),
                        3
                    );
                    $unitCost = Decimal::round((string) ($item->inputProduct->purchase_price ?? '0'), 4);
                    $lineCost = Decimal::round(bcmul($quantity, $unitCost, 7), 4);
                    $inputCost = bcadd($inputCost, $lineCost, 4);

                    $this->consumeInput($order, $item->inputProduct, null, (float) $quantity, $request->user()->id, $quantities, 'production_formula_input_global');
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
                $inputCost = Decimal::round(bcmul($inputMeters, (string) ($inputProduct->purchase_price ?? '0'), 7), 4);
                $this->consumeInput($order, $inputProduct, $inputCoilId, (float) $inputMeters, $request->user()->id, $quantities);
                $order->inputs()->create([
                    'product_id' => $inputProduct->id,
                    'product_coil_id' => $inputCoilId,
                    'quantity' => $inputMeters,
                    'unit_label' => $inputProduct->base_unit,
                    'unit_cost' => Decimal::round((string) ($inputProduct->purchase_price ?? '0'), 4),
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
                $this->movement($order, $outputProduct->id, $outputCoil->id, $request->user()->id, (float) $outputMeters, 0, (float) $outputMeters, 'production_output_coil');
            } else {
                $stock = ProductBranchStock::query()->firstOrCreate([
                    'branch_id' => $order->branch_id,
                    'product_id' => $outputProduct->id,
                ], [
                    'available_meters' => 0,
                    'reserved_meters' => 0,
                ]);

                $stock = ProductBranchStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
                [$before, $after] = $quantities->increase($stock, (float) $outputMeters);

                $this->movement($order, $outputProduct->id, null, $request->user()->id, (float) $outputMeters, $before, $after, 'production_output_global');
            }

            $totalCost = bcadd(bcadd($inputCost, $laborCost, 4), $overheadCost, 4);
            $order->update([
                'input_cost' => $inputCost,
                'total_cost' => $totalCost,
                'unit_cost' => Decimal::round(bcdiv($totalCost, $outputMeters, 12), 4),
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

        DB::transaction(function () use ($order, $stage, $data): void {
            $lockedOrder = ProductionOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->status === ProductionOrder::STATUS_COMPLETED
                && $data['status'] !== ProductionOrderStage::STATUS_COMPLETED) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'status' => 'No se puede reabrir una etapa de una producción terminada.',
                ]);
            }

            $lockedStage = ProductionOrderStage::query()
                ->where('production_order_id', $lockedOrder->id)
                ->whereKey($stage->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedStage->update([
                ...$data,
                'completed_at' => $data['status'] === ProductionOrderStage::STATUS_COMPLETED
                    ? ($lockedStage->completed_at ?? now())
                    : null,
                'started_at' => $lockedStage->started_at ?? now(),
            ]);
        });

        SystemCacheInvalidator::bumpOperational();

        return back()->with('success', 'Etapa actualizada correctamente.');
    }

    private function consumeInput(ProductionOrder $order, Product $product, ?int $coilId, float $meters, int $userId, InventoryQuantityService $quantities, string $movementType = 'production_input_global'): void
    {
        $meters = round($meters, 3);
        if (! is_finite($meters) || $meters <= 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'input_meters' => 'El consumo debe ser mayor que cero a precisión de inventario.',
            ]);
        }

        if ($product->inventory_tracking_mode === Product::TRACKING_COIL) {
            $coil = ProductCoil::query()
                ->where('branch_id', $order->branch_id)
                ->where('product_id', $product->id)
                ->where('status', 'available')
                ->lockForUpdate()
                ->findOrFail($coilId);
            $reserved = (float) \App\Modules\Inventory\Models\InventoryReservation::query()
                ->where('product_coil_id', $coil->id)
                ->where('status', \App\Modules\Inventory\Models\InventoryReservation::STATUS_ACTIVE)
                ->sum('meters');
            if (round((float) $coil->available_meters - $reserved, 3) < $meters) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'input_meters' => 'La bobina no tiene cantidad libre suficiente.',
                ]);
            }
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

        if ($quantities->free($stock) < $meters) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'input_meters' => 'El inventario libre no alcanza para producir.',
            ]);
        }

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
