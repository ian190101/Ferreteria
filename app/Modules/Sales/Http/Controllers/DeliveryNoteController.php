<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Http\Requests\StoreDeliveryNoteRequest;
use App\Modules\Sales\Models\DeliveryDriver;
use App\Modules\Sales\Models\DeliveryNote;
use App\Modules\Sales\Models\DeliveryNoteItem;
use App\Modules\Sales\Models\DeliveryTruck;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Sales\Models\SaleReturnItem;
use App\Modules\Sales\Services\SaleInventoryService;
use App\Modules\Sales\Services\SalesWorkflowPolicy;
use App\Support\BranchAccess;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DeliveryNoteController extends Controller
{
    public function index(Request $request): Response
    {
        $deliveries = DeliveryNote::query()
            ->with([
                'sale:id,receipt_number,customer_name,status,total',
                'branch:id,name',
                'user:id,name',
                'driver:id,name,phone,license_number',
                'truck:id,plate,description',
                'items.product:id,name,sku',
                'items.coil:id,barcode,lot_number',
            ])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('sale_id'), fn ($query) => $query->where('sale_id', $request->integer('sale_id')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('delivered_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('delivered_at', '<=', $request->date('to')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();

                $query->where(function ($nested) use ($search) {
                    $nested->where('delivery_number', 'like', "%{$search}%")
                        ->orWhere('recipient_name', 'like', "%{$search}%")
                        ->orWhere('vehicle_plate', 'like', "%{$search}%")
                        ->orWhereHas('sale', fn ($saleQuery) => $saleQuery
                            ->where('receipt_number', 'like', "%{$search}%")
                            ->orWhere('customer_name', 'like', "%{$search}%"));
                });
            })
            ->latest('delivered_at')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        $sales = Sale::query()
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->where('document_type', 'sale_note')
            ->where('status', '!=', 'void')
            ->where('requires_delivery', true)
            ->latest('sold_at')
            ->limit(100)
            ->get(['id', 'branch_id', 'receipt_number', 'customer_name', 'status', 'requires_delivery']);

        return Inertia::render('Sales/Deliveries/Index', [
            'deliveries' => $deliveries,
            'branches' => UiCatalogCache::activeBranchesForUser($request->user()),
            'sales' => $sales,
            'saleItems' => $this->deliverableSaleItems($request),
            'drivers' => $this->drivers($request),
            'trucks' => $this->trucks($request),
            'statuses' => ['partial', 'completed'],
            'filters' => $request->only(['branch_id', 'status', 'sale_id', 'from', 'to', 'search', 'per_page']),
        ]);
    }

    public function store(StoreDeliveryNoteRequest $request, SaleInventoryService $inventory, SalesWorkflowPolicy $workflow): RedirectResponse
    {
        DB::transaction(function () use ($request, $inventory, $workflow) {
            $sale = Sale::query()
                ->where('document_type', 'sale_note')
                ->where('status', '!=', 'void')
                ->where('requires_delivery', true)
                ->lockForUpdate()
                ->findOrFail($request->integer('sale_id'));
            $driver = $request->filled('delivery_driver_id') ? DeliveryDriver::query()->find($request->integer('delivery_driver_id')) : null;
            $truck = $request->filled('delivery_truck_id') ? DeliveryTruck::query()->find($request->integer('delivery_truck_id')) : null;

            $delivery = DeliveryNote::query()->create([
                'sale_id' => $sale->id,
                'branch_id' => $sale->branch_id,
                'user_id' => $request->user()->id,
                'delivery_driver_id' => $driver?->id,
                'delivery_truck_id' => $truck?->id,
                'manual_driver' => $request->boolean('manual_driver'),
                'manual_truck' => $request->boolean('manual_truck'),
                'delivery_number' => $request->validated('delivery_number'),
                'delivered_at' => now(),
                'total_meters' => 0,
                'recipient_name' => $request->validated('recipient_name'),
                'recipient_document' => $request->validated('recipient_document'),
                'recipient_phone' => $request->validated('recipient_phone'),
                'driver_name' => $driver?->name ?? $request->validated('driver_name'),
                'vehicle_plate' => $truck?->plate ?? $request->validated('vehicle_plate'),
                'status' => 'partial',
                'notes' => $request->validated('notes'),
            ]);

            $totalMeters = 0.0;
            $validatedItems = collect($request->validated('items'));
            $metersByItem = $validatedItems
                ->groupBy('sale_item_id')
                ->map(fn ($rows) => round((float) $rows->sum('meters'), 3));
            $saleItems = SaleItem::query()
                ->where('sale_id', $sale->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $returnedByItem = SaleReturnItem::query()
                ->whereIn('sale_item_id', $saleItems->keys())
                ->selectRaw('sale_item_id, SUM(meters) as returned_meters')
                ->groupBy('sale_item_id')
                ->pluck('returned_meters', 'sale_item_id');
            $deliveredByItem = DeliveryNoteItem::query()
                ->whereIn('sale_item_id', $saleItems->keys())
                ->selectRaw('sale_item_id, SUM(meters) as delivered_meters')
                ->groupBy('sale_item_id')
                ->pluck('delivered_meters', 'sale_item_id');
            $pendingByItem = $saleItems->mapWithKeys(fn (SaleItem $saleItem) => [
                $saleItem->id => max(round(
                    (float) $saleItem->meters
                    - (float) ($returnedByItem[$saleItem->id] ?? 0)
                    - (float) ($deliveredByItem[$saleItem->id] ?? 0),
                    3
                ), 0),
            ]);

            foreach ($validatedItems as $itemPayload) {
                $saleItem = $saleItems->get($itemPayload['sale_item_id']);
                $displayQuantity = round((float) ($itemPayload['quantity'] ?? $itemPayload['display_quantity'] ?? 0), 3);
                $meters = $this->quantityToBase($saleItem, $itemPayload);
                $pendingMeters = (float) ($pendingByItem[$saleItem->id] ?? 0);

                if ($meters > $pendingMeters) {
                    throw ValidationException::withMessages([
                        'items' => 'El despacho supera el metraje pendiente del item vendido.',
                    ]);
                }

                $deliveryItem = $delivery->items()->create([
                    'sale_item_id' => $saleItem->id,
                    'product_id' => $saleItem->product_id,
                    'product_coil_id' => $saleItem->product_coil_id,
                    'meters' => $meters,
                    'display_quantity' => $displayQuantity > 0 ? $displayQuantity : $this->baseToDisplayQuantity($saleItem, $meters),
                    'display_unit_label' => $saleItem->display_unit_label ?: $saleItem->unit_label,
                ]);

                if ($workflow->shouldDiscountInventoryOnDelivery()) {
                    $inventory->decrementForDeliveryItem($deliveryItem, (int) $request->user()->id);
                }

                $totalMeters += $meters;
                $pendingByItem[$saleItem->id] = max(round($pendingMeters - $meters, 3), 0);
            }

            $delivery->update([
                'total_meters' => round($totalMeters, 3),
                'status' => $pendingByItem->every(fn (float $pending) => $pending <= 0) ? 'completed' : 'partial',
            ]);
            $sale->update([
                'internal_notes' => trim(implode("\n", array_filter([
                    $sale->internal_notes,
                    "Despacho {$delivery->delivery_number}: ".$this->deliverySummary($delivery),
                ]))),
            ]);
        });

        return redirect()->route('sales.deliveries.index')->with('success', 'Despacho registrado correctamente.');
    }

    public function storeDriver(Request $request): RedirectResponse
    {
        DeliveryDriver::query()->create($this->driverData($request));

        return back()->with('success', 'Conductor creado correctamente.');
    }

    public function updateDriver(Request $request, DeliveryDriver $driver): RedirectResponse
    {
        $this->authorizeCatalogBranch($request, $driver->branch_id);
        $driver->update($this->driverData($request));

        return back()->with('success', 'Conductor actualizado correctamente.');
    }

    public function storeTruck(Request $request): RedirectResponse
    {
        DeliveryTruck::query()->create($this->truckData($request));

        return back()->with('success', 'Camion creado correctamente.');
    }

    public function updateTruck(Request $request, DeliveryTruck $truck): RedirectResponse
    {
        $this->authorizeCatalogBranch($request, $truck->branch_id);
        $truck->update($this->truckData($request, $truck));

        return back()->with('success', 'Camion actualizado correctamente.');
    }

    private function deliverableSaleItems(Request $request)
    {
        return SaleItem::query()
            ->with([
                'sale:id,branch_id,receipt_number,customer_name,status,document_type,requires_delivery',
                'product:id,name,sku,inventory_tracking_mode',
                'coil:id,barcode,lot_number',
            ])
            ->withSum('returnItems as returned_meters_sum', 'meters')
            ->withSum('deliveryItems as delivered_meters_sum', 'meters')
            ->whereHas('sale', fn ($query) => $query
                ->when(true, fn ($saleQuery) => BranchAccess::apply($saleQuery, $request->user()))
                ->where('document_type', 'sale_note')
                ->where('status', '!=', 'void')
                ->where('requires_delivery', true))
            ->latest('id')
            ->limit(300)
            ->get()
            ->map(function (SaleItem $item) {
                $returnedMeters = (float) $item->returned_meters_sum;
                $deliveredMeters = (float) $item->delivered_meters_sum;
                $pendingMeters = max(round((float) $item->meters - $returnedMeters - $deliveredMeters, 3), 0);
                $displayQuantity = (float) $item->display_quantity;
                $baseQuantity = (float) $item->meters;
                $deliveredQuantity = $baseQuantity > 0 && $displayQuantity > 0
                    ? round($deliveredMeters * ($displayQuantity / $baseQuantity), 3)
                    : $deliveredMeters;
                $returnedQuantity = $baseQuantity > 0 && $displayQuantity > 0
                    ? round($returnedMeters * ($displayQuantity / $baseQuantity), 3)
                    : $returnedMeters;
                $pendingQuantity = max(round($displayQuantity - $returnedQuantity - $deliveredQuantity, 3), 0);

                return [
                    'id' => $item->id,
                    'sale_id' => $item->sale_id,
                    'branch_id' => $item->sale?->branch_id,
                    'product_id' => $item->product_id,
                    'product_coil_id' => $item->product_coil_id,
                    'description' => $item->description,
                    'meters' => $item->meters,
                    'display_quantity' => $item->display_quantity,
                    'display_unit_label' => $item->display_unit_label ?: $item->unit_label,
                    'returned_meters' => $returnedMeters,
                    'delivered_meters' => $deliveredMeters,
                    'pending_meters' => $pendingMeters,
                    'returned_quantity' => $returnedQuantity,
                    'delivered_quantity' => $deliveredQuantity,
                    'pending_quantity' => $pendingQuantity,
                    'sale' => $item->sale,
                    'product' => $item->product,
                    'coil' => $item->coil,
                ];
            })
            ->filter(fn (array $item) => $item['pending_meters'] > 0)
            ->values();
    }

    private function drivers(Request $request)
    {
        $branchIds = $request->user()->isSuperAdministrator()
            ? UiCatalogCache::activeBranches(['id'])->pluck('id')
            : collect($request->user()->accessibleBranchIds());

        return DeliveryDriver::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhereIn('branch_id', $branchIds))
            ->orderBy('name')
            ->get(['id', 'branch_id', 'name', 'document_number', 'phone', 'license_number']);
    }

    private function trucks(Request $request)
    {
        $branchIds = $request->user()->isSuperAdministrator()
            ? UiCatalogCache::activeBranches(['id'])->pluck('id')
            : collect($request->user()->accessibleBranchIds());

        return DeliveryTruck::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhereIn('branch_id', $branchIds))
            ->orderBy('plate')
            ->get(['id', 'branch_id', 'plate', 'description', 'brand', 'model', 'capacity']);
    }

    private function quantityToBase(SaleItem $saleItem, array $item): float
    {
        if (filled($item['meters'] ?? null)) {
            return round((float) $item['meters'], 3);
        }

        $quantity = (float) ($item['quantity'] ?? $item['display_quantity'] ?? 0);
        $displayQuantity = (float) $saleItem->display_quantity;

        if ($displayQuantity <= 0) {
            return round($quantity, 3);
        }

        return round($quantity * ((float) $saleItem->meters / $displayQuantity), 3);
    }

    private function baseToDisplayQuantity(SaleItem $saleItem, float $meters): float
    {
        $displayQuantity = (float) $saleItem->display_quantity;
        $baseQuantity = (float) $saleItem->meters;

        if ($baseQuantity <= 0 || $displayQuantity <= 0) {
            return round($meters, 3);
        }

        return round($meters * ($displayQuantity / $baseQuantity), 3);
    }

    private function deliverySummary(DeliveryNote $delivery): string
    {
        $delivery->loadMissing('items.product:id,name');

        return $delivery->items
            ->map(fn (DeliveryNoteItem $item) => sprintf(
                '%s %s de %s',
                number_format((float) $item->display_quantity, 3, '.', ''),
                $item->display_unit_label ?: 'unidad',
                $item->product?->name ?? 'producto'
            ))
            ->join(', ');
    }

    private function driverData(Request $request): array
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:255'],
            'document_number' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:40'],
            'license_number' => ['nullable', 'string', 'max:80'],
            'is_active' => ['required', 'boolean'],
        ]);

        $this->authorizeCatalogBranch($request, $data['branch_id'] ?? null);

        return $data;
    }

    private function truckData(Request $request, ?DeliveryTruck $truck = null): array
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'plate' => ['required', 'string', 'max:40', Rule::unique('delivery_trucks', 'plate')->ignore($truck?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:80'],
            'capacity' => ['nullable', 'numeric', 'min:0', 'max:999999999999.999'],
            'is_active' => ['required', 'boolean'],
        ]);

        $data['plate'] = strtoupper($data['plate']);
        $this->authorizeCatalogBranch($request, $data['branch_id'] ?? null);

        return $data;
    }

    private function authorizeCatalogBranch(Request $request, ?int $branchId): void
    {
        if ($branchId && ! BranchAccess::canAccess($request->user(), $branchId)) {
            abort(403);
        }
    }
}
