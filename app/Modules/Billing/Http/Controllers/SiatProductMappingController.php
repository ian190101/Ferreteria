<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\SiatCatalogItem;
use App\Modules\Billing\Models\SiatProductMapping;
use App\Modules\Inventory\Models\Product;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SiatProductMappingController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Billing/Products/Index', [
            'products' => Product::query()
                ->with(['unit:id,name,symbol', 'siatMapping'])
                ->orderBy('name')
                ->paginate(20),
            'unitMeasures' => SiatCatalogItem::query()
                ->where('catalog_type', 'unit_measures')
                ->orderBy('description')
                ->get(['code', 'description']),
        ]);
    }

    public function store(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'economic_activity_code' => ['required', 'integer'],
            'sin_product_code' => ['required', 'integer'],
            'unit_measure_code' => ['required', 'integer'],
            'fiscal_description' => ['nullable', 'string', 'max:255'],
            'is_invoiceable' => ['boolean'],
        ]);

        SiatProductMapping::query()->updateOrCreate(
            ['product_id' => $product->id],
            [...$data, 'is_invoiceable' => (bool) ($data['is_invoiceable'] ?? true)],
        );

        return back()->with('success', 'Homologacion SIAT del producto guardada.');
    }
}
