<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\Lots\ListProductLotOptionsRequest;
use App\Modules\Inventory\Http\Requests\Lots\UpdateProductLotRequest;
use App\Modules\Inventory\Http\Requests\Lots\VoidProductLotRequest;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Inventory\Services\InventoryQuantityService;
use App\Modules\Inventory\Services\LotAuthorization;
use App\Modules\Inventory\Services\LotTrackingPolicy;
use App\Modules\Inventory\Services\ProductLotService;
use App\Support\BranchAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProductLotController extends Controller
{
    public function store(\App\Modules\Inventory\Http\Requests\Lots\RegisterProductLotsRequest $request, Product $product,
        ProductLotService $service): RedirectResponse
    {
        $service->registerCodes($request->user(), $product, $request->validated()['lot_registrations']);

        return back()->with('success', 'Lotes registrados con sus existencias iniciales.');
    }

    public function index(Request $request, Product $product, \App\Modules\Inventory\Services\ProductLotOverview $overview): Response|JsonResponse
    {
        $data = $overview->forProduct($request, $product);

        return $request->wantsJson() ? response()->json($data) : Inertia::render('Inventory/Lots/Index', $data);
    }

    public function options(ListProductLotOptionsRequest $request, Product $product,
        LotTrackingPolicy $policy, InventoryQuantityService $quantity): JsonResponse
    {
        $data = $request->validated();
        $branchId = (int) $data['branch_id'];
        abort_unless(BranchAccess::canAccess($request->user(), $branchId), 403);
        $configuration = $policy->activeConfiguration();
        if (! $policy->required($product, $configuration)) {
            throw ValidationException::withMessages(['lot_id' => 'El producto no tiene selección de lote activa.']);
        }

        $reserved = DB::table('inventory_reservations')
            ->select('product_coil_id')->selectRaw('SUM(remaining_meters) AS reserved')
            ->where('status', 'active')->groupBy('product_coil_id');
        $query = ProductCoil::query()->where('product_id', $product->id)
            ->where('branch_id', $branchId)->whereNotNull('lot_code')
            ->whereNull('voided_at')->where('status', '!=', 'voided')
            ->leftJoinSub($reserved, 'lot_reservations', 'lot_reservations.product_coil_id', '=', 'product_coils.id')
            ->select('product_coils.*')->selectRaw('COALESCE(lot_reservations.reserved, 0) AS reserved_quantity');

        $query->whereIn('status', ['available', 'depleted']);
        if (! empty($data['search'])) {
            $query->where('lot_code', 'like', '%'.addcslashes($data['search'], '%_\\').'%');
        }

        $page = $query->orderBy('lot_code')->orderBy('product_coils.id')
            ->cursorPaginate((int) ($data['limit'] ?? 25), ['*'], 'cursor', $data['cursor'] ?? null);
        $items = $page->getCollection()->map(fn (ProductCoil $lot) => [
            'lot_id' => $lot->id,
            'lot_code' => $lot->lot_code,
            'physical' => $lot->available_meters,
            'reserved' => $lot->reserved_quantity,
            'free' => $quantity->subtractExact((string) $lot->available_meters, (string) $lot->reserved_quantity),
            'status' => $lot->status,
            'initial_kg' => $lot->initial_kg,
            'initial_meters' => $lot->initial_meters,
            'depleted' => $quantity->compareExact((string) $lot->available_meters, '0') === 0,
            'selectable' => in_array($data['context'], ['purchase', 'receipt', 'adjustment'], true)
                || $quantity->compareExact((string) $lot->available_meters, (string) $lot->reserved_quantity) > 0,
        ]);

        return response()->json(['data' => $items, 'next_cursor' => $page->nextCursor()?->encode()]);
    }

    public function update(UpdateProductLotRequest $request, Product $product, ProductCoil $lot,
        ProductLotService $service): RedirectResponse
    {
        $data = $request->validated();
        $service->update($request->user(), $product, $lot, $data['lot_code'], $data['notes'] ?? null);

        return back()->with('success', 'Lote actualizado.');
    }

    public function void(VoidProductLotRequest $request, Product $product, ProductCoil $lot,
        ProductLotService $service): RedirectResponse
    {
        $service->void($request->user(), $product, $lot, $request->validated()['void_reason']);

        return back()->with('success', 'Lote anulado.');
    }
}
