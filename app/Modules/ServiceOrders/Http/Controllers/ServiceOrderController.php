<?php

namespace App\Modules\ServiceOrders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Models\Customer;
use App\Modules\HumanResources\Models\Worker;
use App\Modules\Inventory\Models\Product;
use App\Modules\ServiceOrders\Models\ServiceOrder;
use App\Modules\ServiceOrders\Services\ServiceOrderInventoryService;
use App\Modules\ServiceOrders\Services\ServiceOrderWorkflowPolicy;
use App\Modules\SystemSuperadmin\Models\BusinessStateDefinition;
use App\Modules\SystemSuperadmin\Services\BusinessStateTransitionService;
use App\Support\BranchAccess;
use App\Support\SystemCacheInvalidator;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ServiceOrderController extends Controller
{
    private const FALLBACK_STATUSES = [
        ServiceOrder::STATUS_PENDING => 'Pendiente',
        ServiceOrder::STATUS_IN_PROGRESS => 'En proceso',
        ServiceOrder::STATUS_FINISHED => 'Terminado',
        ServiceOrder::STATUS_DELIVERED => 'Entregado',
        ServiceOrder::STATUS_CANCELLED => 'Cancelado',
    ];

    public function index(Request $request, ServiceOrderWorkflowPolicy $policy): Response
    {
        $branches = UiCatalogCache::activeBranchesForUser($request->user(), ['id', 'name']);
        $branchId = (int) ($request->integer('branch_id') ?: ($request->user()->branch_id ?? $branches->first()?->id));

        if (! BranchAccess::canAccess($request->user(), $branchId)) {
            $branchId = (int) ($branches->first()?->id ?? 0);
        }

        $orders = ServiceOrder::query()
            ->with(['branch:id,name', 'customer:id,name,phone', 'worker:id,name,position', 'items.product:id,name,sku,base_unit'])
            ->where('branch_id', $branchId)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();
                $query->where(fn ($nested) => $nested
                    ->where('order_number', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', "%{$search}%")));
            })
            ->latest('id')
            ->paginate($request->integer('per_page', 12))
            ->withQueryString();

        return Inertia::render('ServiceOrders/Index', [
            'branches' => $branches,
            'selectedBranchId' => $branchId,
            'policy' => $policy->summary(),
            'orders' => $orders,
            'statuses' => $this->stateOptions(),
            'workers' => Worker::query()
                ->where('is_active', true)
                ->where('branch_id', $branchId)
                ->orderBy('name')
                ->get(['id', 'branch_id', 'name', 'position', 'phone']),
            'customers' => Customer::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'name', 'phone', 'document_number']),
            'materials' => Product::query()
                ->where('is_active', true)
                ->where('is_inventory_item', true)
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'name', 'sku', 'base_unit', 'purchase_price', 'sale_price']),
            'filters' => $request->only(['branch_id', 'status', 'search', 'per_page']),
        ]);
    }

    public function store(Request $request, ServiceOrderWorkflowPolicy $policy, ServiceOrderInventoryService $inventory): RedirectResponse
    {
        abort_unless($policy->ordersEnabled(), 404);

        $data = $this->validated($request, $policy);
        $this->ensureBranchAccess($request, (int) $data['branch_id']);
        $this->validateWorker($data);

        DB::transaction(function () use ($data, $request, $inventory) {
            $materialsAmount = collect($data['items'] ?? [])
                ->sum(fn ($item) => round((float) $item['quantity'] * (float) ($item['unit_price'] ?? 0), 4));
            $laborAmount = (float) ($data['labor_amount'] ?? 0);
            $advanceAmount = (float) ($data['advance_amount'] ?? 0);

            $order = ServiceOrder::query()->create([
                ...collect($data)->except('items')->all(),
                'user_id' => $request->user()->id,
                'order_number' => $this->nextOrderNumber(),
                'status' => ServiceOrder::STATUS_PENDING,
                'materials_amount' => $materialsAmount,
                'labor_amount' => $laborAmount,
                'advance_amount' => $advanceAmount,
                'total_amount' => round($laborAmount + $materialsAmount, 4),
            ]);

            if (! empty($data['items'])) {
                $products = Product::query()->whereIn('id', collect($data['items'])->pluck('product_id')->all())->get()->keyBy('id');
                foreach ($data['items'] as $item) {
                    $product = $products->get((int) $item['product_id']);
                    $quantity = (float) $item['quantity'];
                    $unitPrice = (float) ($item['unit_price'] ?? $product?->sale_price ?? 0);

                    $order->items()->create([
                        'product_id' => $item['product_id'],
                        'quantity' => $quantity,
                        'unit_label' => $item['unit_label'] ?? $product?->base_unit,
                        'unit_cost' => $item['unit_cost'] ?? $product?->purchase_price ?? 0,
                        'unit_price' => $unitPrice,
                        'subtotal' => round($quantity * $unitPrice, 4),
                        'discount_inventory' => $item['discount_inventory'] ?? true,
                        'notes' => $item['notes'] ?? null,
                    ]);
                }

                $inventory->consumeMaterials($order, $request->user()->id);
            }
        });

        SystemCacheInvalidator::bumpOperational();

        return back()->with('success', 'Orden de servicio registrada correctamente.');
    }

    public function updateStatus(Request $request, ServiceOrder $order, BusinessStateTransitionService $transitions): RedirectResponse
    {
        abort_unless(BranchAccess::canAccess($request->user(), (int) $order->branch_id), 403);

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(array_keys($this->stateOptions()))],
        ], [], ['status' => 'estado']);

        if ($this->usesCustomStates() && ! $transitions->canTransition('service_order', $order->status, $data['status'], $request->user())) {
            throw ValidationException::withMessages([
                'status' => 'No se permite cambiar la orden a ese estado con tu rol actual.',
            ]);
        }

        $timestamps = match ($data['status']) {
            ServiceOrder::STATUS_IN_PROGRESS => ['started_at' => now()],
            ServiceOrder::STATUS_FINISHED => ['finished_at' => now()],
            ServiceOrder::STATUS_DELIVERED => ['delivered_at' => now()],
            default => [],
        };

        $order->update([...$data, ...$timestamps]);
        SystemCacheInvalidator::bumpOperational();

        return back()->with('success', 'Estado de la orden actualizado correctamente.');
    }

    private function validated(Request $request, ServiceOrderWorkflowPolicy $policy): array
    {
        $rules = [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'worker_id' => [$policy->technicianRequired() ? 'required' : 'nullable', 'integer', 'exists:workers,id'],
            'customer_name' => ['nullable', 'string', 'max:160'],
            'customer_phone' => ['nullable', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:180'],
            'service_type' => ['nullable', 'string', 'max:80'],
            'scheduled_at' => ['nullable', 'date'],
            'labor_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'advance_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'diagnosis' => ['nullable', 'string', 'max:4000'],
            'work_performed' => ['nullable', 'string', 'max:4000'],
            'warranty_terms' => ['nullable', 'string', 'max:4000'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'items.*.unit_label' => ['nullable', 'string', 'max:40'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'items.*.discount_inventory' => ['boolean'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ];

        $validator = validator($request->all(), $rules, [], [
            'branch_id' => 'sucursal',
            'customer_id' => 'cliente',
            'worker_id' => 'tecnico o responsable',
            'title' => 'titulo de la orden',
            'labor_amount' => 'mano de obra',
            'items' => 'materiales usados',
        ]);

        $validator->after(function ($validator) use ($request, $policy) {
            if ($policy->customerRequired() && ! $request->filled('customer_id') && ! $request->filled('customer_name')) {
                $validator->errors()->add('customer_id', 'Este perfil exige seleccionar un cliente o registrar un cliente manual.');
            }
        });

        return $validator->validate();
    }

    private function validateWorker(array $data): void
    {
        if (empty($data['worker_id'])) {
            return;
        }

        $worker = Worker::query()->findOrFail($data['worker_id']);
        if ((int) $worker->branch_id !== (int) $data['branch_id']) {
            throw ValidationException::withMessages([
                'worker_id' => 'El tecnico o responsable debe pertenecer a la sucursal de la orden.',
            ]);
        }
    }

    private function stateOptions(): array
    {
        $custom = BusinessStateDefinition::query()
            ->where('entity_type', 'service_order')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->pluck('label', 'code')
            ->all();

        return $custom ?: self::FALLBACK_STATUSES;
    }

    private function usesCustomStates(): bool
    {
        return BusinessStateDefinition::query()
            ->where('entity_type', 'service_order')
            ->where('is_active', true)
            ->exists();
    }

    private function ensureBranchAccess(Request $request, int $branchId): void
    {
        abort_unless(BranchAccess::canAccess($request->user(), $branchId), 403);
    }

    private function nextOrderNumber(): string
    {
        return 'OS-'.now()->format('Ymd-His').'-'.random_int(100, 999);
    }
}
