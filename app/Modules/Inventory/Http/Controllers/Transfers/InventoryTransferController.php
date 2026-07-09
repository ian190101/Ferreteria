<?php

namespace App\Modules\Inventory\Http\Controllers\Transfers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\Transfers\StoreInventoryTransferRequest;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryTransfer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Support\BranchAccess;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InventoryTransferController extends Controller
{
    public function index(Request $request): Response
    {
        $transfers = InventoryTransfer::query()
            ->with(['fromBranch:id,name', 'toBranch:id,name', 'product:id,name,sku,inventory_tracking_mode,base_unit,product_unit_id', 'product.unit:id,name,symbol', 'coil:id,barcode,lot_number', 'user:id,name'])
            ->when(! $request->user()->isSuperAdministrator(), fn ($query) => $query
                ->whereIn('from_branch_id', $request->user()->accessibleBranchIds() ?: [-1])
                ->whereIn('to_branch_id', $request->user()->accessibleBranchIds() ?: [-1]))
            ->when($request->filled('from_branch_id'), fn ($query) => $query->where('from_branch_id', $request->integer('from_branch_id')))
            ->when($request->filled('to_branch_id'), fn ($query) => $query->where('to_branch_id', $request->integer('to_branch_id')))
            ->when($request->filled('product_id'), fn ($query) => $query->where('product_id', $request->integer('product_id')))
            ->when($request->filled('search'), fn ($query) => $query->where('transfer_number', 'like', '%'.$request->string('search')->toString().'%'))
            ->latest('transferred_at')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return Inertia::render('Inventory/Transfers/Index', [
            'transfers' => $transfers,
            'branches' => UiCatalogCache::activeBranchesForUser($request->user()),
            'products' => UiCatalogCache::activeProducts(['id', 'name', 'sku', 'inventory_tracking_mode', 'base_unit', 'product_unit_id']),
            'coils' => ProductCoil::query()
                ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
                ->where('status', 'available')
                ->orderByDesc('id')
                ->limit(500)
                ->get(['id', 'branch_id', 'product_id', 'barcode', 'lot_number', 'available_meters']),
            'filters' => $request->only(['from_branch_id', 'to_branch_id', 'product_id', 'search', 'per_page']),
        ]);
    }

    public function store(StoreInventoryTransferRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $product = Product::query()->findOrFail($request->integer('product_id'));
            $meters = round((float) $request->input('meters'), 3);

            $transfer = InventoryTransfer::query()->create([
                'from_branch_id' => $request->integer('from_branch_id'),
                'to_branch_id' => $request->integer('to_branch_id'),
                'product_id' => $product->id,
                'product_coil_id' => $product->inventory_tracking_mode === Product::TRACKING_COIL ? $request->integer('product_coil_id') : null,
                'user_id' => $request->user()->id,
                'transfer_number' => $request->string('transfer_number')->toString(),
                'tracking_mode' => $product->inventory_tracking_mode,
                'meters' => $meters,
                'status' => InventoryTransfer::STATUS_COMPLETED,
                'transferred_at' => now(),
                'reason' => $request->string('reason')->toString(),
                'notes' => $request->string('notes')->toString() ?: null,
            ]);

            if ($product->inventory_tracking_mode === Product::TRACKING_COIL) {
                $this->transferCoil($transfer, $request->user()->id);

                return;
            }

            $this->transferGlobalStock($transfer, $request->user()->id);
        });

        return redirect()->route('inventory.transfers.index')->with('success', 'Transferencia de inventario registrada correctamente.');
    }

    private function transferGlobalStock(InventoryTransfer $transfer, int $userId): void
    {
        $meters = (float) $transfer->meters;
        $sourceStock = ProductBranchStock::query()
            ->where('branch_id', $transfer->from_branch_id)
            ->where('product_id', $transfer->product_id)
            ->lockForUpdate()
            ->firstOrFail();

        $sourceBefore = (float) $sourceStock->available_meters;
        $sourceAfter = round($sourceBefore - $meters, 3);

        if ($sourceAfter < 0) {
            throw ValidationException::withMessages([
                'meters' => 'La sucursal origen no tiene stock global suficiente.',
            ]);
        }

        $sourceStock->update(['available_meters' => $sourceAfter]);

        $destinationStock = ProductBranchStock::query()->firstOrCreate([
            'branch_id' => $transfer->to_branch_id,
            'product_id' => $transfer->product_id,
        ], [
            'available_meters' => 0,
            'reserved_meters' => 0,
        ]);

        $destinationStock = ProductBranchStock::query()->whereKey($destinationStock->id)->lockForUpdate()->firstOrFail();
        $destinationBefore = (float) $destinationStock->available_meters;
        $destinationAfter = round($destinationBefore + $meters, 3);
        $destinationStock->update(['available_meters' => $destinationAfter]);

        $this->createMovement($transfer, $userId, $transfer->from_branch_id, 'transfer_out_global', -$meters, $sourceBefore, $sourceAfter, null);
        $this->createMovement($transfer, $userId, $transfer->to_branch_id, 'transfer_in_global', $meters, $destinationBefore, $destinationAfter, null);
    }

    private function transferCoil(InventoryTransfer $transfer, int $userId): void
    {
        $coil = ProductCoil::query()->lockForUpdate()->findOrFail($transfer->product_coil_id);
        $meters = (float) $coil->available_meters;

        if ((int) $coil->branch_id !== (int) $transfer->from_branch_id || (int) $coil->product_id !== (int) $transfer->product_id || $coil->status !== 'available') {
            throw ValidationException::withMessages([
                'product_coil_id' => 'La bobina ya no esta disponible en la sucursal origen.',
            ]);
        }

        $coil->update(['branch_id' => $transfer->to_branch_id]);

        $this->createMovement($transfer, $userId, $transfer->from_branch_id, 'transfer_out_coil', -$meters, $meters, 0, $coil->id);
        $this->createMovement($transfer, $userId, $transfer->to_branch_id, 'transfer_in_coil', $meters, 0, $meters, $coil->id);
    }

    private function createMovement(InventoryTransfer $transfer, int $userId, int $branchId, string $type, float $delta, float $before, float $after, ?int $coilId): void
    {
        InventoryMovement::query()->create([
            'branch_id' => $branchId,
            'product_id' => $transfer->product_id,
            'product_coil_id' => $coilId,
            'user_id' => $userId,
            'source_type' => InventoryTransfer::class,
            'source_id' => $transfer->id,
            'type' => $type,
            'meters_delta' => $delta,
            'meters_before' => $before,
            'meters_after' => $after,
            'reason' => $transfer->reason,
            'created_at' => $transfer->transferred_at,
        ]);
    }
}
