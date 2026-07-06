<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Sales\Http\Requests\StoreSaleReturnRequest;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Sales\Models\SaleReturnItem;
use App\Support\BranchAccess;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SaleReturnController extends Controller
{
    public function index(Request $request): Response
    {
        $returns = SaleReturn::query()
            ->with([
                'sale:id,receipt_number,customer_name,status,total',
                'branch:id,name',
                'user:id,name',
                'items.product:id,name,sku',
                'items.coil:id,barcode,lot_number',
            ])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('sale_id'), fn ($query) => $query->where('sale_id', $request->integer('sale_id')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('returned_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('returned_at', '<=', $request->date('to')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();

                $query->where(function ($nested) use ($search) {
                    $nested->where('return_number', 'like', "%{$search}%")
                        ->orWhere('reason', 'like', "%{$search}%")
                        ->orWhereHas('sale', fn ($saleQuery) => $saleQuery
                            ->where('receipt_number', 'like', "%{$search}%")
                            ->orWhere('customer_name', 'like', "%{$search}%"));
                });
            })
            ->latest('returned_at')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        $sales = Sale::query()
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->where('document_type', 'sale_note')
            ->where('status', '!=', 'void')
            ->latest('sold_at')
            ->limit(100)
            ->get(['id', 'branch_id', 'receipt_number', 'customer_name', 'status']);

        return Inertia::render('Sales/Returns/Index', [
            'returns' => $returns,
            'branches' => UiCatalogCache::activeBranchesForUser($request->user()),
            'sales' => $sales,
            'saleItems' => $this->returnableSaleItems($request),
            'filters' => $request->only(['branch_id', 'sale_id', 'from', 'to', 'search', 'per_page']),
        ]);
    }

    public function store(StoreSaleReturnRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $sale = Sale::query()
                ->where('document_type', 'sale_note')
                ->where('status', '!=', 'void')
                ->lockForUpdate()
                ->findOrFail($request->integer('sale_id'));

            $saleReturn = SaleReturn::query()->create([
                'sale_id' => $sale->id,
                'branch_id' => $sale->branch_id,
                'user_id' => $request->user()->id,
                'return_number' => $request->validated('return_number'),
                'returned_at' => now(),
                'total_amount' => 0,
                'reason' => $request->validated('reason'),
                'notes' => $request->validated('notes'),
            ]);

            $totalAmount = 0.0;

            foreach ($request->validated('items') as $itemPayload) {
                $saleItem = SaleItem::query()
                    ->with('product:id,name,inventory_tracking_mode')
                    ->where('sale_id', $sale->id)
                    ->lockForUpdate()
                    ->findOrFail($itemPayload['sale_item_id']);

                $meters = round((float) $itemPayload['meters'], 3);
                $remainingMeters = $this->remainingMeters($saleItem);

                if ($meters > $remainingMeters) {
                    throw ValidationException::withMessages([
                        'items' => 'La devolucion supera el metraje disponible del item vendido.',
                    ]);
                }

                $discountAmount = $this->proportionalDiscount($saleItem, $meters);
                $total = max(round(((float) $saleItem->unit_price * $meters) - $discountAmount, 2), 0);

                $saleReturnItem = $saleReturn->items()->create([
                    'sale_item_id' => $saleItem->id,
                    'product_id' => $saleItem->product_id,
                    'product_coil_id' => $saleItem->product_coil_id,
                    'meters' => $meters,
                    'unit_price' => $saleItem->unit_price,
                    'discount_amount' => $discountAmount,
                    'total' => $total,
                ]);

                $this->restoreInventory($sale, $saleReturn, $saleItem, $saleReturnItem, $request->user()->id);
                $totalAmount += $total;
            }

            $saleReturn->update(['total_amount' => round($totalAmount, 2)]);
            $sale->update([
                'internal_notes' => trim(implode("\n", array_filter([
                    $sale->internal_notes,
                    "Devolucion {$saleReturn->return_number}: {$saleReturn->reason}",
                ]))),
            ]);
        });

        return redirect()->route('sales.returns.index')->with('success', 'Devolucion registrada correctamente.');
    }

    private function returnableSaleItems(Request $request)
    {
        return SaleItem::query()
            ->with([
                'sale:id,branch_id,receipt_number,customer_name,status,document_type',
                'product:id,name,sku,inventory_tracking_mode',
                'coil:id,barcode,lot_number',
            ])
            ->withSum('returnItems as returned_meters_sum', 'meters')
            ->whereHas('sale', fn ($query) => $query
                ->when(true, fn ($saleQuery) => BranchAccess::apply($saleQuery, $request->user()))
                ->where('document_type', 'sale_note')
                ->where('status', '!=', 'void'))
            ->latest('id')
            ->limit(300)
            ->get()
            ->map(function (SaleItem $item) {
                $returnedMeters = (float) $item->returned_meters_sum;
                $remainingMeters = max(round((float) $item->meters - $returnedMeters, 3), 0);

                return [
                    'id' => $item->id,
                    'sale_id' => $item->sale_id,
                    'branch_id' => $item->sale?->branch_id,
                    'product_id' => $item->product_id,
                    'product_coil_id' => $item->product_coil_id,
                    'description' => $item->description,
                    'meters' => $item->meters,
                    'returned_meters' => $returnedMeters,
                    'remaining_meters' => $remainingMeters,
                    'unit_price' => $item->unit_price,
                    'sale' => $item->sale,
                    'product' => $item->product,
                    'coil' => $item->coil,
                ];
            })
            ->filter(fn (array $item) => $item['remaining_meters'] > 0)
            ->values();
    }

    private function remainingMeters(SaleItem $saleItem): float
    {
        $returnedMeters = (float) SaleReturnItem::query()
            ->where('sale_item_id', $saleItem->id)
            ->sum('meters');

        return max(round((float) $saleItem->meters - $returnedMeters, 3), 0);
    }

    private function proportionalDiscount(SaleItem $saleItem, float $meters): float
    {
        if ((float) $saleItem->meters <= 0) {
            return 0;
        }

        return round(((float) $saleItem->discount_amount / (float) $saleItem->meters) * $meters, 2);
    }

    private function restoreInventory(Sale $sale, SaleReturn $saleReturn, SaleItem $saleItem, SaleReturnItem $returnItem, int $userId): void
    {
        if ($saleItem->product_coil_id) {
            $coil = ProductCoil::query()->lockForUpdate()->findOrFail($saleItem->product_coil_id);
            $before = (float) $coil->available_meters;
            $after = round($before + (float) $returnItem->meters, 3);

            $coil->update([
                'available_meters' => $after,
                'status' => 'available',
            ]);

            $this->movement($sale, $saleReturn, $returnItem, $userId, $before, $after, $coil->id);

            return;
        }

        $stock = ProductBranchStock::query()->firstOrCreate([
            'branch_id' => $sale->branch_id,
            'product_id' => $saleItem->product_id,
        ], [
            'available_meters' => 0,
            'reserved_meters' => 0,
        ]);
        $stock = ProductBranchStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
        $before = (float) $stock->available_meters;
        $after = round($before + (float) $returnItem->meters, 3);

        $stock->update(['available_meters' => $after]);
        $this->movement($sale, $saleReturn, $returnItem, $userId, $before, $after, null);
    }

    private function movement(Sale $sale, SaleReturn $saleReturn, SaleReturnItem $returnItem, int $userId, float $before, float $after, ?int $coilId): void
    {
        InventoryMovement::query()->create([
            'branch_id' => $sale->branch_id,
            'product_id' => $returnItem->product_id,
            'product_coil_id' => $coilId,
            'user_id' => $userId,
            'source_type' => SaleReturnItem::class,
            'source_id' => $returnItem->id,
            'type' => 'sale_return',
            'meters_delta' => (float) $returnItem->meters,
            'meters_before' => $before,
            'meters_after' => $after,
            'reason' => "Devolucion {$saleReturn->return_number}",
            'created_at' => $saleReturn->returned_at,
        ]);
    }
}
