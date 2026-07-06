<?php

namespace App\Modules\Inventory\Http\Controllers\Reservations;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\Reservations\StoreInventoryReservationRequest;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Sales\Models\Sale;
use App\Support\BranchAccess;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InventoryReservationController extends Controller
{
    public function index(Request $request): Response
    {
        $reservations = InventoryReservation::query()
            ->with(['branch:id,name', 'product:id,name,sku,inventory_tracking_mode', 'coil:id,barcode,lot_number', 'sale:id,receipt_number', 'consumedSale:id,receipt_number', 'user:id,name'])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('product_id'), fn ($query) => $query->where('product_id', $request->integer('product_id')))
            ->latest()
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return Inertia::render('Inventory/Reservations/Index', [
            'reservations' => $reservations,
            'branches' => UiCatalogCache::activeBranchesForUser($request->user()),
            'products' => UiCatalogCache::activeProducts(),
            'coils' => ProductCoil::query()
                ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
                ->where('status', 'available')
                ->orderByDesc('id')
                ->limit(500)
                ->get(['id', 'branch_id', 'product_id', 'barcode', 'lot_number', 'available_meters']),
            'quotations' => Sale::query()
                ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
                ->where('document_type', 'quotation')
                ->where('status', 'quoted')
                ->latest('sold_at')
                ->limit(100)
                ->get(['id', 'branch_id', 'receipt_number', 'customer_name']),
            'filters' => $request->only(['branch_id', 'status', 'product_id', 'per_page']),
            'statuses' => [
                InventoryReservation::STATUS_ACTIVE,
                InventoryReservation::STATUS_RELEASED,
                InventoryReservation::STATUS_CONSUMED,
            ],
        ]);
    }

    public function store(StoreInventoryReservationRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $product = Product::query()->lockForUpdate()->findOrFail($request->integer('product_id'));
            $meters = round((float) $request->input('meters'), 3);
            $coilId = $request->filled('product_coil_id') ? $request->integer('product_coil_id') : null;

            if ($product->inventory_tracking_mode === Product::TRACKING_GLOBAL) {
                $stock = ProductBranchStock::query()
                    ->where('branch_id', $request->integer('branch_id'))
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $freeMeters = (float) $stock->available_meters - (float) $stock->reserved_meters;

                if ($freeMeters < $meters) {
                    throw ValidationException::withMessages(['meters' => 'El stock global libre no alcanza para reservar ese metraje.']);
                }

                $stock->update(['reserved_meters' => round((float) $stock->reserved_meters + $meters, 3)]);
            } else {
                $coil = ProductCoil::query()
                    ->where('branch_id', $request->integer('branch_id'))
                    ->where('product_id', $product->id)
                    ->where('status', 'available')
                    ->lockForUpdate()
                    ->findOrFail($coilId);

                $reserved = (float) InventoryReservation::query()
                    ->where('product_coil_id', $coil->id)
                    ->where('status', InventoryReservation::STATUS_ACTIVE)
                    ->sum('meters');

                if (((float) $coil->available_meters - $reserved) < $meters) {
                    throw ValidationException::withMessages(['meters' => 'La bobina no tiene metraje libre suficiente para reservar.']);
                }
            }

            InventoryReservation::query()->create([
                'branch_id' => $request->integer('branch_id'),
                'product_id' => $product->id,
                'product_coil_id' => $coilId,
                'sale_id' => $request->filled('sale_id') ? $request->integer('sale_id') : null,
                'user_id' => $request->user()->id,
                'meters' => $meters,
                'status' => InventoryReservation::STATUS_ACTIVE,
                'expires_at' => $request->date('expires_at'),
                'reason' => $request->string('reason')->toString() ?: null,
                'notes' => $request->string('notes')->toString() ?: null,
            ]);
        });

        return redirect()->route('inventory.reservations.index')->with('success', 'Reserva registrada correctamente.');
    }

    public function release(Request $request, InventoryReservation $reservation): RedirectResponse
    {
        abort_unless($request->user()?->can('inventory.reservations.manage'), 403);
        abort_unless(BranchAccess::canAccess($request->user(), (int) $reservation->branch_id), 403);

        DB::transaction(function () use ($reservation) {
            $reservation = InventoryReservation::query()->lockForUpdate()->findOrFail($reservation->id);

            if ($reservation->status !== InventoryReservation::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['reservation' => 'Solo se pueden liberar reservas activas.']);
            }

            $this->releaseGlobalMetersIfNeeded($reservation);

            $reservation->update([
                'status' => InventoryReservation::STATUS_RELEASED,
                'released_at' => now(),
            ]);
        });

        return redirect()->route('inventory.reservations.index')->with('success', 'Reserva liberada correctamente.');
    }

    private function releaseGlobalMetersIfNeeded(InventoryReservation $reservation): void
    {
        if ($reservation->product_coil_id) {
            return;
        }

        $stock = ProductBranchStock::query()
            ->where('branch_id', $reservation->branch_id)
            ->where('product_id', $reservation->product_id)
            ->lockForUpdate()
            ->first();

        if (! $stock) {
            return;
        }

        $stock->update([
            'reserved_meters' => max(round((float) $stock->reserved_meters - (float) $reservation->meters, 3), 0),
        ]);
    }
}
