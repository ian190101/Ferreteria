<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCoil;
use App\Support\BranchAccess;
use App\Support\UiCatalogCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductLotOverview
{
    public function forProduct(Request $request, Product $product): array
    {
        $reserved = DB::table('inventory_reservations')->select('product_coil_id')
            ->selectRaw('SUM(remaining_meters) AS reserved')->where('status', 'active')->groupBy('product_coil_id');
        $base = BranchAccess::apply(ProductCoil::query()->where('product_coils.product_id', $product->id), $request->user(), 'product_coils.branch_id')
            ->when($request->filled('branch_id'), fn ($query) => $query->where('product_coils.branch_id', $request->integer('branch_id')));
        $summary = (clone $base)->selectRaw('COUNT(*) AS total,
            SUM(CASE WHEN voided_at IS NULL AND available_meters > 0 THEN 1 ELSE 0 END) AS stocked,
            SUM(CASE WHEN voided_at IS NULL AND available_meters = 0 THEN 1 ELSE 0 END) AS depleted')->first();
        $lots = $base->when($request->filled('status'), function ($query) use ($request): void {
                if ($request->input('status') === 'voided') {
                    $query->whereNotNull('product_coils.voided_at');
                } else {
                    $query->whereNull('product_coils.voided_at')->where('product_coils.status', $request->input('status'));
                }
            })
            ->leftJoinSub($reserved, 'lot_reservations', 'lot_reservations.product_coil_id', '=', 'product_coils.id')
            ->select('product_coils.*')->selectRaw('COALESCE(lot_reservations.reserved, 0) AS reserved_quantity')
            ->with(['branch:id,name', 'movements' => fn ($query) => $query->orderByDesc('id')->limit(10),
                'audits' => fn ($query) => $query->orderByDesc('id')->limit(10)])
            ->orderBy('product_coils.id')->cursorPaginate(25)->withQueryString();
        $quantity = app(InventoryQuantityService::class);
        $lots->getCollection()->each(function (ProductCoil $lot) use ($quantity): void {
            $lot->setAttribute('free_quantity', $lot->isVoided() ? '0.000' : $quantity->subtractExact((string) $lot->available_meters, (string) $lot->reserved_quantity));
            $lot->setAttribute('blocked_quantity', $lot->isVoided() ? (string) $lot->available_meters : '0.000');
        });
        $policy = app(LotTrackingPolicy::class);

        return [
            'product' => $product->only(['id', 'name', 'base_unit', 'requires_lot', 'inventory_tracking_mode']),
            'lots' => $lots,
            'summary' => $summary,
            'canRegisterLots' => $request->user()->can('inventory.products.manage') && $product->is_active
                && $policy->required($product, $policy->activeConfiguration()),
            'canManageLots' => $lots->getCollection()->mapWithKeys(fn (ProductCoil $lot) => [$lot->id => app(LotAuthorization::class)->canManage($request->user(), $lot)]),
            'branches' => UiCatalogCache::activeBranchesForUser($request->user()),
            'filters' => $request->only(['branch_id', 'status']),
        ];
    }
}
