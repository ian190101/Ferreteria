<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Thickness;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProductCatalogController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Inventory/Products/Catalogs', [
            'categories' => ProductCategory::query()
                ->with(['defaultUnit:id,name,symbol'])
                ->withCount('products')
                ->orderBy('name')
                ->get(),
            'units' => ProductUnit::query()
                ->withCount('products')
                ->orderBy('name')
                ->get(),
            'thicknesses' => Thickness::query()
                ->withCount('products')
                ->orderBy('millimeters')
                ->get(),
        ]);
    }

    public function storeUnit(Request $request): RedirectResponse
    {
        ProductUnit::query()->create($this->unitData($request));
        UiCatalogCache::forgetProductCatalogs();

        return back()->with('success', 'Unidad creada correctamente.');
    }

    public function updateUnit(Request $request, ProductUnit $unit): RedirectResponse
    {
        $unit->update($this->unitData($request, $unit));

        Product::query()
            ->where('product_unit_id', $unit->id)
            ->update(['base_unit' => $unit->symbol]);
        UiCatalogCache::forgetProductCatalogs();

        return back()->with('success', 'Unidad actualizada correctamente.');
    }

    public function storeThickness(Request $request): RedirectResponse
    {
        Thickness::query()->create($this->thicknessData($request));
        UiCatalogCache::forgetProductCatalogs();

        return back()->with('success', 'Espesor creado correctamente.');
    }

    public function updateThickness(Request $request, Thickness $thickness): RedirectResponse
    {
        $thickness->update($this->thicknessData($request, $thickness));
        UiCatalogCache::forgetProductCatalogs();

        return back()->with('success', 'Espesor actualizado correctamente.');
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        ProductCategory::query()->create($this->categoryData($request));
        UiCatalogCache::forgetProductCatalogs();

        return back()->with('success', 'Categoria creada correctamente.');
    }

    public function updateCategory(Request $request, ProductCategory $category): RedirectResponse
    {
        $category->update($this->categoryData($request, $category));

        Product::query()
            ->where('product_category_id', $category->id)
            ->update(['category' => $category->name]);
        UiCatalogCache::forgetProductCatalogs();

        return back()->with('success', 'Categoria actualizada correctamente.');
    }

    private function unitData(Request $request, ?ProductUnit $unit = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'symbol' => ['required', 'string', 'max:24', Rule::unique('product_units', 'symbol')->ignore($unit?->id)],
            'kind' => ['required', Rule::in(['cantidad', 'longitud', 'peso', 'volumen'])],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    private function thicknessData(Request $request, ?Thickness $thickness = null): array
    {
        if ($request->filled('kg_per_meter')) {
            $kgPerMeter = (float) $request->input('kg_per_meter');

            if ($kgPerMeter > 0) {
                $request->merge([
                    'kg_to_meter_factor' => round(1 / $kgPerMeter, 6),
                ]);
            }
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'millimeters' => [
                'required',
                'numeric',
                'gt:0',
                'max:9999.9999',
                Rule::unique('thicknesses', 'millimeters')->ignore($thickness?->id),
            ],
            'kg_per_meter' => ['required', 'numeric', 'gt:0', 'max:999999999999.999999'],
            'kg_to_meter_factor' => ['required', 'numeric', 'gt:0', 'max:999999999999.999999'],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    private function categoryData(Request $request, ?ProductCategory $category = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('product_categories', 'name')->ignore($category?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'default_unit_id' => ['required', 'integer', 'exists:product_units,id'],
            'default_tracking_mode' => ['required', Rule::in([Product::TRACKING_GLOBAL, Product::TRACKING_COIL])],
            'requires_thickness' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);
    }

}
