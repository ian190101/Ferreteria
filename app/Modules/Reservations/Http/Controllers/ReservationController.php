<?php

namespace App\Modules\Reservations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Models\Customer;
use App\Modules\HumanResources\Models\Worker;
use App\Modules\Inventory\Models\Product;
use App\Modules\Reservations\Models\BusinessReservation;
use App\Modules\Reservations\Services\ReservationAvailabilityService;
use App\Modules\Reservations\Services\ReservationWorkflowPolicy;
use App\Modules\SystemSuperadmin\Models\BusinessStateDefinition;
use App\Modules\SystemSuperadmin\Models\ReservableResource;
use App\Modules\SystemSuperadmin\Services\BusinessStateTransitionService;
use App\Support\BranchAccess;
use App\Support\SystemCacheInvalidator;
use App\Support\UiCatalogCache;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ReservationController extends Controller
{
    private const FALLBACK_STATUSES = [
        BusinessReservation::STATUS_PENDING => 'Pendiente',
        BusinessReservation::STATUS_CONFIRMED => 'Confirmada',
        BusinessReservation::STATUS_IN_PROGRESS => 'En proceso / entregado',
        BusinessReservation::STATUS_COMPLETED => 'Finalizada / devuelto',
        BusinessReservation::STATUS_CANCELLED => 'Cancelada',
        BusinessReservation::STATUS_NO_SHOW => 'No asistio',
        BusinessReservation::STATUS_RESCHEDULED => 'Reprogramada',
    ];

    public function index(Request $request, ReservationWorkflowPolicy $policy): Response
    {
        abort_unless($policy->reservationsEnabled(), 404);

        $branches = UiCatalogCache::activeBranchesForUser($request->user(), ['id', 'name']);
        $branchId = (int) ($request->integer('branch_id') ?: ($request->user()->branch_id ?? $branches->first()?->id));

        if (! BranchAccess::canAccess($request->user(), $branchId)) {
            $branchId = (int) ($branches->first()?->id ?? 0);
        }

        $reservations = BusinessReservation::query()
            ->with([
                'branch:id,name',
                'customer:id,name,phone',
                'resource:id,branch_id,type,code,name,capacity',
                'worker:id,name,position',
                'items.product:id,name,sku,base_unit,item_type',
            ])
            ->where('branch_id', $branchId)
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')->toString()))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();
                $query->where(fn ($nested) => $nested
                    ->where('reservation_number', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('resource', fn ($resource) => $resource->where('name', 'like', "%{$search}%")));
            })
            ->orderBy('start_at')
            ->paginate($request->integer('per_page', 12))
            ->withQueryString();

        return Inertia::render('Reservations/Index', [
            'branches' => $branches,
            'selectedBranchId' => $branchId,
            'policy' => $policy->summary(),
            'reservations' => $reservations,
            'statuses' => $this->stateOptions(),
            'resources' => ReservableResource::query()
                ->where('branch_id', $branchId)
                ->where('is_active', true)
                ->orderBy('type')
                ->orderBy('name')
                ->get(['id', 'branch_id', 'type', 'code', 'name', 'capacity']),
            'workers' => Worker::query()
                ->where('branch_id', $branchId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'branch_id', 'name', 'position', 'phone']),
            'customers' => Customer::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'name', 'phone', 'document_number']),
            'products' => Product::query()
                ->where('is_active', true)
                ->where('is_sellable', true)
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'name', 'sku', 'base_unit', 'item_type', 'sale_price']),
            'filters' => $request->only(['branch_id', 'type', 'status', 'search', 'per_page']),
        ]);
    }

    public function store(Request $request, ReservationWorkflowPolicy $policy, ReservationAvailabilityService $availability): RedirectResponse
    {
        abort_unless($policy->reservationsEnabled(), 404);

        $data = $this->validated($request, $policy);
        $type = $data['type'] ?? BusinessReservation::TYPE_RESERVATION;

        if ($type === BusinessReservation::TYPE_RENTAL && ! $policy->rentalsEnabled()) {
            abort(404);
        }

        abort_unless(BranchAccess::canAccess($request->user(), (int) $data['branch_id']), 403);
        $this->validateResourceBranch($data);
        $this->validateWorkerBranch($data);

        $startAt = Carbon::parse($data['start_at']);
        $endAt = Carbon::parse($data['end_at']);
        $availability->ensureResourceAvailable((int) ($data['reservable_resource_id'] ?? 0) ?: null, $startAt, $endAt);

        DB::transaction(function () use ($data, $request, $type) {
            $itemsTotal = collect($data['items'] ?? [])
                ->sum(fn ($item) => round((float) $item['quantity'] * (float) ($item['unit_price'] ?? 0), 4));
            $baseAmount = (float) ($data['amount'] ?? 0);
            $penaltyAmount = (float) ($data['penalty_amount'] ?? 0);
            $depositAmount = (float) ($data['deposit_amount'] ?? 0);

            $reservation = BusinessReservation::query()->create([
                ...collect($data)->except('items')->all(),
                'user_id' => $request->user()->id,
                'reservation_number' => $this->nextReservationNumber($type),
                'status' => BusinessReservation::STATUS_PENDING,
                'amount' => round($baseAmount + $itemsTotal, 4),
                'penalty_amount' => $penaltyAmount,
                'deposit_amount' => $depositAmount,
                'total_amount' => round($baseAmount + $itemsTotal + $penaltyAmount + $depositAmount, 4),
            ]);

            if (! empty($data['items'])) {
                $products = Product::query()
                    ->whereIn('id', collect($data['items'])->pluck('product_id')->filter()->all())
                    ->get()
                    ->keyBy('id');

                foreach ($data['items'] as $item) {
                    $product = filled($item['product_id'] ?? null) ? $products->get((int) $item['product_id']) : null;
                    $quantity = (float) $item['quantity'];
                    $unitPrice = (float) ($item['unit_price'] ?? $product?->sale_price ?? 0);

                    $reservation->items()->create([
                        'product_id' => $item['product_id'] ?? null,
                        'description' => $item['description'] ?? $product?->name ?? 'Item reservado',
                        'quantity' => $quantity,
                        'unit_label' => $item['unit_label'] ?? $product?->base_unit,
                        'unit_price' => $unitPrice,
                        'subtotal' => round($quantity * $unitPrice, 4),
                        'notes' => $item['notes'] ?? null,
                    ]);
                }
            }
        });

        SystemCacheInvalidator::bumpOperational();

        return back()->with('success', $type === BusinessReservation::TYPE_RENTAL
            ? 'Alquiler registrado correctamente.'
            : 'Reserva registrada correctamente.');
    }

    public function updateStatus(Request $request, BusinessReservation $reservation, BusinessStateTransitionService $transitions): RedirectResponse
    {
        abort_unless(BranchAccess::canAccess($request->user(), (int) $reservation->branch_id), 403);

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(array_keys($this->stateOptions()))],
            'condition_after' => ['nullable', 'string', 'max:4000'],
        ], [], [
            'status' => 'estado',
            'condition_after' => 'estado despues de devolver',
        ]);

        $entity = $reservation->type === BusinessReservation::TYPE_RENTAL ? 'rental' : 'reservation';
        if ($this->usesCustomStates($entity) && ! $transitions->canTransition($entity, $reservation->status, $data['status'], $request->user())) {
            throw ValidationException::withMessages([
                'status' => 'No se permite cambiar a ese estado con tu rol actual.',
            ]);
        }

        $timestamps = match ($data['status']) {
            BusinessReservation::STATUS_IN_PROGRESS => ['checked_out_at' => now()],
            BusinessReservation::STATUS_COMPLETED => ['returned_at' => now()],
            default => [],
        };

        $reservation->update([
            'status' => $data['status'],
            'condition_after' => $data['condition_after'] ?? $reservation->condition_after,
            ...$timestamps,
        ]);

        SystemCacheInvalidator::bumpOperational();

        return back()->with('success', 'Estado actualizado correctamente.');
    }

    private function validated(Request $request, ReservationWorkflowPolicy $policy): array
    {
        $type = $request->string('type', BusinessReservation::TYPE_RESERVATION)->toString();
        $resourceRequired = $policy->resourceRequired($type);
        $conditionRequired = $policy->conditionCheckRequired($type);

        $validator = validator($request->all(), [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'reservable_resource_id' => [$resourceRequired ? 'required' : 'nullable', 'integer', 'exists:reservable_resources,id'],
            'worker_id' => ['nullable', 'integer', 'exists:workers,id'],
            'type' => ['required', 'string', Rule::in([BusinessReservation::TYPE_RESERVATION, BusinessReservation::TYPE_RENTAL])],
            'channel' => ['nullable', 'string', 'max:60'],
            'customer_name' => ['nullable', 'string', 'max:160'],
            'customer_phone' => ['nullable', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:180'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'advance_amount' => [$policy->advanceEnabled() ? 'nullable' : 'prohibited', 'numeric', 'min:0', 'max:999999999'],
            'deposit_amount' => [$policy->depositRequired() && $type === BusinessReservation::TYPE_RENTAL ? 'required' : 'nullable', 'numeric', 'min:0', 'max:999999999'],
            'penalty_amount' => [$policy->penaltyEnabled() && $type === BusinessReservation::TYPE_RENTAL ? 'nullable' : 'prohibited', 'numeric', 'min:0', 'max:999999999'],
            'condition_before' => [$conditionRequired ? 'required' : 'nullable', 'string', 'max:4000'],
            'condition_after' => ['nullable', 'string', 'max:4000'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.description' => ['nullable', 'string', 'max:180'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'gt:0', 'max:999999'],
            'items.*.unit_label' => ['nullable', 'string', 'max:40'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ], [], [
            'branch_id' => 'sucursal',
            'customer_id' => 'cliente',
            'reservable_resource_id' => 'recurso reservable',
            'worker_id' => 'responsable',
            'type' => 'tipo',
            'title' => 'titulo',
            'start_at' => 'inicio',
            'end_at' => 'fin o devolucion',
            'advance_amount' => 'anticipo',
            'deposit_amount' => 'garantia',
            'penalty_amount' => 'penalidad',
            'condition_before' => 'estado antes de entregar',
            'items' => 'items',
        ]);

        $validator->after(function ($validator) use ($request, $policy) {
            if ($policy->customerRequired() && ! $request->filled('customer_id') && ! $request->filled('customer_name')) {
                $validator->errors()->add('customer_id', 'Este perfil exige cliente registrado o cliente manual.');
            }
        });

        return $validator->validate();
    }

    private function validateResourceBranch(array $data): void
    {
        if (empty($data['reservable_resource_id'])) {
            return;
        }

        $resource = ReservableResource::query()->findOrFail($data['reservable_resource_id']);
        if ((int) $resource->branch_id !== (int) $data['branch_id']) {
            throw ValidationException::withMessages([
                'reservable_resource_id' => 'El recurso debe pertenecer a la sucursal seleccionada.',
            ]);
        }
    }

    private function validateWorkerBranch(array $data): void
    {
        if (empty($data['worker_id'])) {
            return;
        }

        $worker = Worker::query()->findOrFail($data['worker_id']);
        if ((int) $worker->branch_id !== (int) $data['branch_id']) {
            throw ValidationException::withMessages([
                'worker_id' => 'El responsable debe pertenecer a la sucursal seleccionada.',
            ]);
        }
    }

    private function stateOptions(): array
    {
        $custom = BusinessStateDefinition::query()
            ->whereIn('entity_type', ['reservation', 'rental'])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->pluck('label', 'code')
            ->all();

        return $custom ?: self::FALLBACK_STATUSES;
    }

    private function usesCustomStates(string $entityType): bool
    {
        return BusinessStateDefinition::query()
            ->where('entity_type', $entityType)
            ->where('is_active', true)
            ->exists();
    }

    private function nextReservationNumber(string $type): string
    {
        $prefix = $type === BusinessReservation::TYPE_RENTAL ? 'ALQ' : 'RES';

        return $prefix.'-'.now()->format('Ymd-His').'-'.random_int(100, 999);
    }
}
