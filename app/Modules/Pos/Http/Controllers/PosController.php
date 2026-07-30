<?php

namespace App\Modules\Pos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Pos\Services\PosWorkflowPolicy;
use App\Modules\Sales\Services\CommercialPolicy;
use App\Modules\Sales\Services\SalesDocumentPolicy;
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
        $posPolicy = app(PosWorkflowPolicy::class);
        $branches = UiCatalogCache::activeBranchesForUser($request->user(), ['id', 'name']);
        $branchId = (int) ($request->integer('branch_id') ?: ($request->user()->branch_id ?? $branches->first()?->id));

        if (! BranchAccess::canAccess($request->user(), $branchId)) {
            $branchId = (int) ($branches->first()?->id ?? 0);
        }

        $allowedItemTypes = $posPolicy->allowedItemTypes();

        $products = Product::query()
            ->with(array_filter([
                'unit:id,name,symbol,kind',
                'branchStocks' => fn ($query) => $query
                    ->where('branch_id', $branchId),
                $posPolicy->usesImages() ? 'primaryImage:id,product_id,url,path,alt_text' : null,
                $posPolicy->usesVariants() ? 'activeVariants:id,product_id,sku,barcode,name,attributes,sale_price,is_active' : null,
            ]))
            ->where('is_active', true)
            ->where('is_sellable', true)
            ->where('inventory_tracking_mode', Product::TRACKING_GLOBAL)
            ->whereIn('item_type', $allowedItemTypes)
            ->whereHas('branchStocks', fn ($query) => $query
                ->where('branch_id', $branchId)
                ->where('is_enabled', true))
            ->orderBy('name')
            ->limit(700)
            ->get([
                'id',
                'product_unit_id',
                'name',
                'sku',
                'barcode',
                'base_unit',
                'sale_price',
                'inventory_tracking_mode',
                'item_type',
                'is_inventory_item',
                'duration_minutes',
                'preparation_minutes',
            ])
            ->map(function (Product $product) use ($branchId, $posPolicy): array {
                $price = app(CommercialPolicy::class)->priceFor($product, $branchId);

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'barcode' => $product->barcode,
                    'unit' => $product->unit?->symbol ?: $product->base_unit ?: 'u',
                    'sale_price' => $price,
                    'stock' => (float) ($product->branchStocks->first()?->available_meters ?? 0),
                    'tracking' => $product->inventory_tracking_mode,
                    'item_type' => $product->item_type ?: 'physical',
                    'is_inventory_item' => (bool) $product->is_inventory_item,
                    'duration_minutes' => $product->duration_minutes,
                    'preparation_minutes' => $product->preparation_minutes,
                    'image_url' => $posPolicy->usesImages() ? ($product->primaryImage?->url ?: $product->primaryImage?->path) : null,
                    'image_alt' => $posPolicy->usesImages() ? ($product->primaryImage?->alt_text ?: $product->name) : null,
                    'variants' => $posPolicy->usesVariants()
                        ? $product->activeVariants->map(fn ($variant) => [
                            'id' => $variant->id,
                            'name' => $variant->name,
                            'sku' => $variant->sku,
                            'barcode' => $variant->barcode,
                            'attributes' => $variant->attributes ?? [],
                            'sale_price' => $variant->sale_price !== null ? (float) $variant->sale_price : $price,
                        ])->values()
                        : [],
                ];
            })
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
                ->where('is_sellable', true)
                ->where('inventory_tracking_mode', Product::TRACKING_COIL)
                ->whereHas('branchStocks', fn ($query) => $query
                    ->where('branch_id', $branchId)
                    ->where('is_enabled', true))
                ->count(),
            'posPolicy' => $posPolicy->summary(),
        ]);
    }
}
