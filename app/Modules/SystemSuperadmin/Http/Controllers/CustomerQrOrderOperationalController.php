<?php

namespace App\Modules\SystemSuperadmin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branches\Models\Branch;
use App\Modules\SystemSuperadmin\Models\CustomerQrChannel;
use App\Modules\SystemSuperadmin\Models\CustomerQrOrder;
use App\Modules\SystemSuperadmin\Services\CustomerQrOrderConversionService;
use App\Modules\SystemSuperadmin\Services\CustomerQrOrderInventoryService;
use App\Modules\SystemSuperadmin\Services\OperationalTraceService;
use App\Support\BranchAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomerQrOrderOperationalController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $orders = CustomerQrOrder::query()
            ->with(['branch:id,name', 'channel:id,name,code,service_mode,context_type'])
            ->when(! $user->isSuperAdministrator(), fn ($query) => BranchAccess::apply($query, $user))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('order_type'), fn ($query) => $query->where('order_type', $request->string('order_type')->toString()))
            ->when($request->filled('channel_id'), fn ($query) => $query->where('customer_qr_channel_id', $request->integer('channel_id')))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('submitted_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('submitted_at', '<=', $request->date('date_to')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = '%'.$request->string('search')->trim()->toString().'%';

                $query->where(function ($nested) use ($search) {
                    $nested->where('public_code', 'like', $search)
                        ->orWhere('customer_name', 'like', $search)
                        ->orWhere('customer_phone', 'like', $search)
                        ->orWhere('table_or_reference', 'like', $search);
                });
            })
            ->latest('submitted_at')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (CustomerQrOrder $order): array => $this->present($order));

        return Inertia::render('CustomerQrOrders/Index', [
            'orders' => $orders,
            'summary' => $this->summary($request),
            'filters' => $request->only(['branch_id', 'status', 'order_type', 'channel_id', 'date_from', 'date_to', 'search']),
            'branches' => Branch::query()
                ->when(! $user->isSuperAdministrator(), fn ($query) => $query->whereIn('id', $user->accessibleBranchIds() ?: [-1]))
                ->orderBy('name')
                ->get(['id', 'name']),
            'channels' => CustomerQrChannel::query()
                ->when(! $user->isSuperAdministrator(), fn ($query) => $query->whereIn('branch_id', $user->accessibleBranchIds() ?: [-1]))
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'branch_id']),
            'statuses' => $this->statuses(),
            'orderTypes' => ['pickup', 'table', 'delivery', 'quote_request', 'service', 'reservation'],
            'conversionTargets' => [
                CustomerQrOrderConversionService::TYPE_SALE => 'Venta',
                CustomerQrOrderConversionService::TYPE_QUOTATION => 'Cotizacion',
                CustomerQrOrderConversionService::TYPE_KITCHEN_ORDER => 'Comanda',
                CustomerQrOrderConversionService::TYPE_SERVICE_ORDER => 'Orden de servicio',
                CustomerQrOrderConversionService::TYPE_RESERVATION => 'Reserva',
            ],
            'can' => [
                'accept' => $user->can('customer-qr-orders.accept'),
                'reject' => $user->can('customer-qr-orders.reject'),
                'convert' => $user->can('customer-qr-orders.convert'),
                'manageChannels' => $user->can('customer-qr-orders.manage-channels'),
            ],
        ]);
    }

    public function accept(CustomerQrOrder $order, Request $request, CustomerQrOrderInventoryService $inventory, OperationalTraceService $traces): RedirectResponse
    {
        $this->authorizeOrder($request, $order);

        if (! in_array($order->status, [CustomerQrOrder::STATUS_PENDING], true)) {
            return back()->with('warning', 'Solo se pueden aceptar pedidos pendientes.');
        }

        $inventory->reserve($order, $request->user());
        $order->update([
            'status' => CustomerQrOrder::STATUS_ACCEPTED,
            'accepted_by_id' => $request->user()->id,
            'accepted_at' => now(),
        ]);

        $traces->recordModel($order, 'customer_qr_order_accepted', $request->user(), $order->branch_id, ['public_code' => $order->public_code], source: 'qr_operations');

        return back()->with('success', 'Pedido QR aceptado y stock reservado si corresponde.');
    }

    public function reject(CustomerQrOrder $order, Request $request, CustomerQrOrderInventoryService $inventory, OperationalTraceService $traces): RedirectResponse
    {
        $this->authorizeOrder($request, $order);

        if ($order->converted_at) {
            return back()->with('warning', 'No se puede rechazar un pedido ya convertido.');
        }

        $inventory->release($order);
        $data = $request->validate([
            'internal_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $order->update([
            'status' => CustomerQrOrder::STATUS_REJECTED,
            'rejected_by_id' => $request->user()->id,
            'rejected_at' => now(),
            'internal_notes' => $data['internal_notes'] ?? $order->internal_notes,
        ]);

        $traces->recordModel($order, 'customer_qr_order_rejected', $request->user(), $order->branch_id, ['public_code' => $order->public_code], source: 'qr_operations');

        return back()->with('success', 'Pedido QR rechazado.');
    }

    public function status(CustomerQrOrder $order, Request $request, OperationalTraceService $traces): RedirectResponse
    {
        $this->authorizeOrder($request, $order);

        $data = $request->validate([
            'status' => ['required', Rule::in([CustomerQrOrder::STATUS_PREPARING, CustomerQrOrder::STATUS_READY, CustomerQrOrder::STATUS_DELIVERED])],
        ]);

        if (in_array($order->status, [CustomerQrOrder::STATUS_REJECTED], true)) {
            return back()->with('warning', 'No se puede cambiar el estado operativo de un pedido rechazado.');
        }

        $status = $data['status'];
        $timestamps = [
            CustomerQrOrder::STATUS_PREPARING => 'prepared_at',
            CustomerQrOrder::STATUS_READY => 'ready_at',
            CustomerQrOrder::STATUS_DELIVERED => 'delivered_at',
        ];

        $order->update([
            'status' => $status,
            $timestamps[$status] => now(),
        ]);

        $traces->recordModel($order, 'customer_qr_order_'.$status, $request->user(), $order->branch_id, ['public_code' => $order->public_code], source: 'qr_operations');

        return back()->with('success', 'Estado del pedido QR actualizado.');
    }

    public function convert(CustomerQrOrder $order, Request $request, CustomerQrOrderConversionService $converter): RedirectResponse
    {
        $this->authorizeOrder($request, $order);

        $data = $request->validate([
            'target_type' => ['required', Rule::in([
                CustomerQrOrderConversionService::TYPE_SALE,
                CustomerQrOrderConversionService::TYPE_QUOTATION,
                CustomerQrOrderConversionService::TYPE_KITCHEN_ORDER,
                CustomerQrOrderConversionService::TYPE_SERVICE_ORDER,
                CustomerQrOrderConversionService::TYPE_RESERVATION,
            ])],
        ]);

        $result = $converter->convert($order->load('channel'), $data['target_type'], $request->user());

        return back()->with('success', 'Pedido QR convertido a '.$result['label'].'.');
    }

    private function authorizeOrder(Request $request, CustomerQrOrder $order): void
    {
        abort_unless(BranchAccess::canAccess($request->user(), $order->branch_id), 403);
    }

    private function present(CustomerQrOrder $order): array
    {
        return [
            'id' => $order->id,
            'public_code' => $order->public_code,
            'status' => $order->status,
            'status_label' => $this->statuses()[$order->status] ?? $order->status,
            'order_type' => $order->order_type,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'table_or_reference' => $order->table_or_reference,
            'notes' => $order->notes,
            'items' => $order->items ?? [],
            'total' => (float) $order->total,
            'total_label' => 'Bs '.number_format((float) $order->total, 2, ',', '.'),
            'branch' => $order->branch?->name,
            'channel' => $order->channel?->name,
            'submitted_at' => $order->submitted_at?->format('d/m/Y H:i'),
            'converted_to_type' => $order->converted_to_type,
            'converted_to_id' => $order->converted_to_id,
        ];
    }

    private function summary(Request $request): array
    {
        $user = $request->user();
        $query = CustomerQrOrder::query()
            ->when(! $user->isSuperAdministrator(), fn ($query) => BranchAccess::apply($query, $user));

        return [
            'pending' => (clone $query)->where('status', CustomerQrOrder::STATUS_PENDING)->count(),
            'accepted' => (clone $query)->where('status', CustomerQrOrder::STATUS_ACCEPTED)->count(),
            'ready' => (clone $query)->where('status', CustomerQrOrder::STATUS_READY)->count(),
            'delivered' => (clone $query)->where('status', CustomerQrOrder::STATUS_DELIVERED)->count(),
        ];
    }

    private function statuses(): array
    {
        return [
            CustomerQrOrder::STATUS_PENDING => 'Pendiente',
            CustomerQrOrder::STATUS_ACCEPTED => 'Aceptado',
            CustomerQrOrder::STATUS_PREPARING => 'En preparacion',
            CustomerQrOrder::STATUS_READY => 'Listo',
            CustomerQrOrder::STATUS_DELIVERED => 'Entregado',
            CustomerQrOrder::STATUS_REJECTED => 'Rechazado',
            CustomerQrOrder::STATUS_CONVERTED => 'Convertido',
        ];
    }
}
