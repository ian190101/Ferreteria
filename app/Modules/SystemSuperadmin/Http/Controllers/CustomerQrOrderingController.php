<?php

namespace App\Modules\SystemSuperadmin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SystemSuperadmin\Services\CustomerQrOrderingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomerQrOrderingController extends Controller
{
    public function show(Request $request, string $token, CustomerQrOrderingService $service): Response
    {
        $channel = $service->findPublicChannel($token);

        abort_unless($channel, 404);

        return Inertia::render('CustomerQrOrdering/Show', [
            'channel' => [
                'name' => $channel->name,
                'code' => $channel->code,
                'context_type' => $channel->context_type,
                'context_reference' => $channel->context_reference,
                'service_mode' => $channel->service_mode,
                'branch' => $channel->branch?->only(['id', 'name', 'address']),
                'requires_customer_name' => $channel->requires_customer_name,
                'requires_customer_phone' => $channel->requires_customer_phone,
                'requires_table_or_reference' => $channel->requires_table_or_reference,
                'allowed_order_types' => $channel->allowed_order_types ?: $service->settings()['allowed_order_types'],
            ],
            'settings' => $service->settings(),
            'menu' => $service->menuFor($channel),
            'orderStatus' => $service->publicStatus($channel, $request->query('pedido')),
        ]);
    }

    public function store(Request $request, string $token, CustomerQrOrderingService $service): RedirectResponse
    {
        $channel = $service->findPublicChannel($token);

        abort_unless($channel, 404);

        $settings = $service->settings();
        $data = $request->validate([
            'order_type' => ['required', 'string', Rule::in($channel->allowed_order_types ?: $settings['allowed_order_types'])],
            'customer_name' => [$channel->requires_customer_name || $settings['requires_customer_name'] ? 'required' : 'nullable', 'string', 'max:160'],
            'customer_phone' => [$channel->requires_customer_phone || $settings['requires_customer_phone'] ? 'required' : 'nullable', 'string', 'max:40'],
            'table_or_reference' => [$channel->requires_table_or_reference ? 'required' : 'nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:'.$settings['max_items_per_order']],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:'.$settings['max_quantity_per_item']],
            'items.*.notes' => ['nullable', 'string', 'max:300'],
        ]);

        $order = $service->createOrder($channel, $data, $request);

        return redirect()
            ->route('customer-qr-ordering.show', ['token' => $token, 'pedido' => $order->public_code])
            ->with('success', 'Pedido '.$order->public_code.' recibido correctamente.');
    }

    public function svg(string $token, CustomerQrOrderingService $service): HttpResponse
    {
        $channel = $service->findPublicChannel($token);

        abort_unless($channel, 404);

        return response($service->qrSvg($channel), 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
