<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Http\Requests\StoreDeliveryNoteRequest;
use App\Modules\Sales\Models\DeliveryNote;
use App\Modules\Sales\Models\DeliveryNoteItem;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Sales\Models\SaleReturnItem;
use App\Support\BranchAccess;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            ->latest('sold_at')
            ->limit(100)
            ->get(['id', 'branch_id', 'receipt_number', 'customer_name', 'status']);

        return Inertia::render('Sales/Deliveries/Index', [
            'deliveries' => $deliveries,
            'branches' => UiCatalogCache::activeBranchesForUser($request->user()),
            'sales' => $sales,
            'saleItems' => $this->deliverableSaleItems($request),
            'statuses' => ['partial', 'completed'],
            'filters' => $request->only(['branch_id', 'status', 'sale_id', 'from', 'to', 'search', 'per_page']),
        ]);
    }

    public function store(StoreDeliveryNoteRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $sale = Sale::query()
                ->where('document_type', 'sale_note')
                ->where('status', '!=', 'void')
                ->lockForUpdate()
                ->findOrFail($request->integer('sale_id'));

            $delivery = DeliveryNote::query()->create([
                'sale_id' => $sale->id,
                'branch_id' => $sale->branch_id,
                'user_id' => $request->user()->id,
                'delivery_number' => $request->validated('delivery_number'),
                'delivered_at' => now(),
                'total_meters' => 0,
                'recipient_name' => $request->validated('recipient_name'),
                'recipient_document' => $request->validated('recipient_document'),
                'recipient_phone' => $request->validated('recipient_phone'),
                'driver_name' => $request->validated('driver_name'),
                'vehicle_plate' => $request->validated('vehicle_plate'),
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
                $meters = round((float) $itemPayload['meters'], 3);
                $pendingMeters = (float) ($pendingByItem[$saleItem->id] ?? 0);

                if ($meters > $pendingMeters) {
                    throw ValidationException::withMessages([
                        'items' => 'El despacho supera el metraje pendiente del item vendido.',
                    ]);
                }

                $delivery->items()->create([
                    'sale_item_id' => $saleItem->id,
                    'product_id' => $saleItem->product_id,
                    'product_coil_id' => $saleItem->product_coil_id,
                    'meters' => $meters,
                ]);

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
                    "Despacho {$delivery->delivery_number}: {$delivery->total_meters} m",
                ]))),
            ]);
        });

        return redirect()->route('sales.deliveries.index')->with('success', 'Despacho registrado correctamente.');
    }

    private function deliverableSaleItems(Request $request)
    {
        return SaleItem::query()
            ->with([
                'sale:id,branch_id,receipt_number,customer_name,status,document_type',
                'product:id,name,sku,inventory_tracking_mode',
                'coil:id,barcode,lot_number',
            ])
            ->withSum('returnItems as returned_meters_sum', 'meters')
            ->withSum('deliveryItems as delivered_meters_sum', 'meters')
            ->whereHas('sale', fn ($query) => $query
                ->when(true, fn ($saleQuery) => BranchAccess::apply($saleQuery, $request->user()))
                ->where('document_type', 'sale_note')
                ->where('status', '!=', 'void'))
            ->latest('id')
            ->limit(300)
            ->get()
            ->map(function (SaleItem $item) {
                $returnedMeters = (float) $item->returned_meters_sum;
                $deliveredMeters = (float) $item->delivered_meters_sum;
                $pendingMeters = max(round((float) $item->meters - $returnedMeters - $deliveredMeters, 3), 0);

                return [
                    'id' => $item->id,
                    'sale_id' => $item->sale_id,
                    'branch_id' => $item->sale?->branch_id,
                    'product_id' => $item->product_id,
                    'product_coil_id' => $item->product_coil_id,
                    'description' => $item->description,
                    'meters' => $item->meters,
                    'returned_meters' => $returnedMeters,
                    'delivered_meters' => $deliveredMeters,
                    'pending_meters' => $pendingMeters,
                    'sale' => $item->sale,
                    'product' => $item->product,
                    'coil' => $item->coil,
                ];
            })
            ->filter(fn (array $item) => $item['pending_meters'] > 0)
            ->values();
    }
}
