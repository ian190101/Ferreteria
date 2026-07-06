<?php

namespace App\Modules\Purchases\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchases\Http\Requests\StoreSupplierRequest;
use App\Modules\Purchases\Http\Requests\UpdateSupplierRequest;
use App\Modules\Purchases\Models\PurchaseItem;
use App\Modules\Purchases\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    public function index(Request $request): Response
    {
        $suppliers = Supplier::query()
            ->withCount('purchases')
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request) {
                $search = $request->string('search')->toString();

                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('tax_id', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->latest('id')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return Inertia::render('Purchases/Suppliers/Index', [
            'suppliers' => $suppliers,
            'filters' => $request->only('search', 'is_active', 'per_page'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Purchases/Suppliers/Form', [
            'supplier' => null,
        ]);
    }

    public function statement(Request $request, Supplier $supplier): Response
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', 'string', 'max:40'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $from = filled($validated['from'] ?? null) ? Carbon::parse($validated['from'])->startOfDay() : null;
        $to = filled($validated['to'] ?? null) ? Carbon::parse($validated['to'])->endOfDay() : null;
        $status = $validated['status'] ?? null;
        $perPage = (int) ($validated['per_page'] ?? 10);

        $purchasesBase = $supplier->purchases()
            ->when($from, fn (Builder $query) => $query->where('purchase_date', '>=', $from->toDateString()))
            ->when($to, fn (Builder $query) => $query->where('purchase_date', '<=', $to->toDateString()))
            ->when(filled($status), fn (Builder $query) => $query->where('status', $status));

        $itemsBase = PurchaseItem::query()
            ->whereHas('purchase', function (Builder $query) use ($supplier, $from, $to, $status) {
                $query->where('supplier_id', $supplier->id)
                    ->when($from, fn (Builder $query) => $query->where('purchase_date', '>=', $from->toDateString()))
                    ->when($to, fn (Builder $query) => $query->where('purchase_date', '<=', $to->toDateString()))
                    ->when(filled($status), fn (Builder $query) => $query->where('status', $status));
            });

        return Inertia::render('Purchases/Suppliers/Statement', [
            'supplier' => $supplier,
            'metrics' => [
                'purchases_count' => (clone $purchasesBase)->count(),
                'purchases_total' => (float) (clone $purchasesBase)->sum('total_amount'),
                'paid_total' => (float) (clone $purchasesBase)->sum('paid_amount'),
                'balance_due' => (float) (clone $purchasesBase)->sum('balance_due'),
                'received_total' => (float) (clone $purchasesBase)->where('status', 'received')->sum('total_amount'),
                'pending_total' => (float) (clone $purchasesBase)->where('status', 'pending')->sum('total_amount'),
                'meters_total' => (float) (clone $itemsBase)->sum('meters'),
                'kilograms_total' => (float) (clone $itemsBase)->sum('kilograms'),
            ],
            'purchases' => (clone $purchasesBase)
                ->with(['branch:id,name', 'user:id,name'])
                ->withCount('items')
                ->latest('purchase_date')
                ->paginate($perPage, ['*'], 'purchases_page')
                ->withQueryString(),
            'items' => (clone $itemsBase)
                ->with(['purchase:id,document_number,purchase_date,status,supplier_id', 'product:id,name,sku'])
                ->latest('id')
                ->paginate($perPage, ['*'], 'items_page')
                ->withQueryString(),
            'filters' => [
                'from' => $validated['from'] ?? '',
                'to' => $validated['to'] ?? '',
                'status' => $status ?? '',
                'per_page' => $perPage,
            ],
        ]);
    }

    public function store(StoreSupplierRequest $request): RedirectResponse
    {
        Supplier::query()->create($request->validated());

        return redirect()->route('purchases.suppliers.index')->with('success', 'Proveedor creado correctamente.');
    }

    public function edit(Supplier $supplier): Response
    {
        return Inertia::render('Purchases/Suppliers/Form', [
            'supplier' => $supplier,
        ]);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($request->validated());

        return redirect()->route('purchases.suppliers.index')->with('success', 'Proveedor actualizado correctamente.');
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        $supplier->update(['is_active' => false]);
        $supplier->delete();

        return redirect()->route('purchases.suppliers.index')->with('success', 'Proveedor desactivado correctamente.');
    }
}
