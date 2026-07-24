<?php

namespace App\Modules\Pos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Services\CommercialPolicy;
use App\Modules\Sales\Services\SalesDocumentPolicy;
use App\Modules\SystemSuperadmin\Services\ActiveBusinessProfile;
use App\Support\BranchAccess;
use App\Support\UiCatalogCache;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PosController extends Controller
{
    public function index(Request $request): Response
    {
        $documentPolicy = app(SalesDocumentPolicy::class);
        $branches = UiCatalogCache::activeBranchesForUser($request->user(), ['id', 'name']);
        $branchId = (int) ($request->integer('branch_id') ?: ($request->user()->branch_id ?? $branches->first()?->id));

        if (! BranchAccess::canAccess($request->user(), $branchId)) {
            $branchId = (int) ($branches->first()?->id ?? 0);
        }

        $products = Product::query()
            ->with([
                'unit:id,name,symbol,kind',
                'branchStocks' => fn ($query) => $query
                    ->where('branch_id', $branchId),
            ])
            ->where('is_active', true)
            ->where('inventory_tracking_mode', Product::TRACKING_GLOBAL)
            ->whereHas('branchStocks', fn ($query) => $query
                ->where('branch_id', $branchId)
                ->where('is_enabled', true))
            ->orderBy('name')
            ->limit(700)
            ->get(['id', 'product_unit_id', 'name', 'sku', 'barcode', 'base_unit', 'sale_price', 'inventory_tracking_mode'])
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'unit' => $product->unit?->symbol ?: $product->base_unit ?: 'u',
                'sale_price' => app(CommercialPolicy::class)->priceFor($product, $branchId),
                'stock' => (float) ($product->branchStocks->first()?->available_meters ?? 0),
                'tracking' => $product->inventory_tracking_mode,
            ])
            ->values();

        return Inertia::render('Pos/Index', [
            'branches' => $branches,
            'selectedBranchId' => $branchId,
            'saleTypes' => UiCatalogCache::saleTypes(),
            'currencies' => UiCatalogCache::currencies(),
            'paymentMethods' => UiCatalogCache::activePaymentMethods(['id', 'name', 'code', 'requires_reference'])
                ->filter(fn ($method) => $documentPolicy->isPaymentMethodAllowed($method->code, 'pos'))
                ->values(),
            'documentPolicy' => $documentPolicy->summary(),
            'products' => $products,
            'excludedTrackedProducts' => Product::query()
                ->where('is_active', true)
                ->where('inventory_tracking_mode', Product::TRACKING_COIL)
                ->count(),
            'posPolicy' => [
                'scannerMode' => (string) (ActiveBusinessProfile::payload()['pos']['scanner_mode'] ?? 'optional'),
                'offlineMode' => (string) (ActiveBusinessProfile::payload()['pos']['offline_mode'] ?? 'disabled'),
                'customerPrompt' => (string) (ActiveBusinessProfile::payload()['pos']['customer_prompt'] ?? 'optional'),
            ],
        ]);
    }
}
