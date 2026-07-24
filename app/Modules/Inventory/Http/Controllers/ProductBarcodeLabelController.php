<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\BarcodeLabelTemplate;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\Code39Barcode;
use App\Support\BranchAccess;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductBarcodeLabelController extends Controller
{
    public function show(Request $request, Product $product, Code39Barcode $barcode): Response
    {
        $template = BarcodeLabelTemplate::query()
            ->where('is_active', true)
            ->when($request->filled('template_id'), fn ($query) => $query->whereKey($request->integer('template_id')))
            ->orderByDesc('is_default')
            ->latest('id')
            ->first();

        $template ??= BarcodeLabelTemplate::query()->create([
            'name' => 'Etiqueta producto 50x30',
            'paper_type' => 'label_50x30',
            'is_default' => true,
        ]);

        $quantity = max($request->integer('quantity', 1), 1);

        return Inertia::render('Inventory/Products/BarcodeLabel', [
            'product' => $product->load(['unit:id,name,symbol']),
            'template' => $template,
            'templates' => BarcodeLabelTemplate::query()->where('is_active', true)->orderBy('name')->get(),
            'branches' => UiCatalogCache::activeBranchesForUser($request->user(), ['id', 'name']),
            'quantity' => $quantity,
            'barcodeSvg' => $barcode->svg((string) $product->barcode, (int) $template->barcode_height_mm * 4),
        ]);
    }

    public function updateTemplate(Request $request, BarcodeLabelTemplate $template): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:120'],
            'paper_type' => ['required', 'string', 'max:40'],
            'label_width_mm' => ['required', 'integer', 'min:20', 'max:210'],
            'label_height_mm' => ['required', 'integer', 'min:10', 'max:297'],
            'margin_mm' => ['required', 'integer', 'min:0', 'max:20'],
            'barcode_height_mm' => ['required', 'integer', 'min:8', 'max:120'],
            'font_size' => ['required', 'integer', 'min:6', 'max:24'],
            'show_product_name' => ['boolean'],
            'show_sku' => ['boolean'],
            'show_price' => ['boolean'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        if (filled($data['branch_id'])) {
            abort_unless(BranchAccess::canAccess($request->user(), (int) $data['branch_id']), 403);
        }

        if ($request->boolean('is_default')) {
            BarcodeLabelTemplate::query()->whereKeyNot($template->id)->update(['is_default' => false]);
        }

        $template->update($data);

        return back()->with('success', 'Plantilla de codigo de barras actualizada correctamente.');
    }
}
