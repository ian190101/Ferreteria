<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\SystemSuperadmin\Models\CustomerQrChannel;
use App\Modules\SystemSuperadmin\Models\CustomerQrOrder;
use App\Support\SystemCacheInvalidator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerQrOrderingService
{
    public const MODULE = 'customer_qr_ordering';

    public const CAPABILITY = 'uses_customer_qr_ordering';

    public const FEATURE_FLAG = 'customer_qr_ordering_engine';

    public function __construct(
        private readonly SimpleQrSvgRenderer $qr,
        private readonly OperationalTraceService $traces,
    )
    {
    }

    public function isEnabled(): bool
    {
        $profile = ActiveBusinessProfile::payload();

        return (bool) data_get($profile, 'modules.'.self::MODULE, false)
            && (bool) data_get($profile, 'capabilities.'.self::CAPABILITY, false)
            && (bool) data_get($profile, 'feature_flags.'.self::FEATURE_FLAG, false)
            && (bool) data_get($profile, 'customer_qr_ordering.enabled', false);
    }

    public function publicUrl(CustomerQrChannel $channel): string
    {
        return route('customer-qr-ordering.show', $channel->token);
    }

    public function qrSvg(CustomerQrChannel $channel): string
    {
        return $this->qr->render($this->publicUrl($channel));
    }

    public function findPublicChannel(string $token): ?CustomerQrChannel
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $channel = CustomerQrChannel::query()
            ->with('branch:id,name,address')
            ->where('token', $token)
            ->first();

        if (! $channel || ! $channel->is_active) {
            return null;
        }

        if ($channel->expires_at && $channel->expires_at->isPast()) {
            return null;
        }

        return $channel;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function menuFor(CustomerQrChannel $channel): array
    {
        $ttl = max(1, (int) data_get(ActiveBusinessProfile::payload(), 'customer_qr_ordering.cache_menu_minutes', 5));
        $key = 'customer-qr-menu:'.$channel->id.':v'.SystemCacheInvalidator::operationalVersion();

        return Cache::remember($key, now()->addMinutes($ttl), function () use ($channel): array {
            $withImages = (bool) data_get(ActiveBusinessProfile::payload(), 'products.images_enabled', false);

            return Product::query()
                ->with([
                    'productCategory:id,name',
                    ...($withImages ? ['primaryImage:id,product_id,url,path,alt_text'] : []),
                ])
                ->where('is_active', true)
                ->where('is_sellable', true)
                ->orderBy('name')
                ->limit(120)
                ->get(['id', 'product_category_id', 'name', 'category', 'sku', 'barcode', 'base_unit', 'item_type', 'sale_price'])
                ->map(fn (Product $product): array => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'category' => $product->productCategory?->name ?? $product->category,
                    'sku' => $product->sku,
                    'barcode' => $product->barcode,
                    'base_unit' => $product->base_unit,
                    'item_type' => $product->item_type,
                    'sale_price' => (float) $product->sale_price,
                    'display_price' => 'Bs '.number_format((float) $product->sale_price, 2, ',', '.'),
                    'branch_id' => $channel->branch_id,
                    'image_url' => $withImages ? $this->imageUrl($product) : null,
                ])
                ->values()
                ->all();
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createOrder(CustomerQrChannel $channel, array $payload, Request $request): CustomerQrOrder
    {
        if (! $this->findPublicChannel($channel->token)) {
            throw ValidationException::withMessages([
                'channel' => 'Este QR no esta disponible para recibir pedidos.',
            ]);
        }

        $settings = $this->settings();
        $items = collect($payload['items'] ?? [])
            ->filter(fn (array $item): bool => (int) ($item['product_id'] ?? 0) > 0 && (float) ($item['quantity'] ?? 0) > 0)
            ->take((int) $settings['max_items_per_order'])
            ->values();

        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'Selecciona al menos un producto o servicio.',
            ]);
        }

        $allowedOrderTypes = $channel->allowed_order_types ?: $settings['allowed_order_types'];
        $orderType = (string) ($payload['order_type'] ?? 'pickup');

        if (! in_array($orderType, $allowedOrderTypes, true)) {
            throw ValidationException::withMessages([
                'order_type' => 'El tipo de pedido no esta permitido para este QR.',
            ]);
        }

        $products = $this->productsFor($items);
        $normalizedItems = $this->normalizeItems($items, $products, (float) $settings['max_quantity_per_item']);
        $this->ensureStockAvailable($channel, $normalizedItems);
        $subtotal = round($normalizedItems->sum('subtotal'), 2);
        $serviceFee = round((float) data_get($channel->settings, 'service_fee', 0), 2);

        return DB::transaction(function () use ($channel, $orderType, $payload, $normalizedItems, $subtotal, $serviceFee, $request): CustomerQrOrder {
            $order = CustomerQrOrder::query()->create([
                'customer_qr_channel_id' => $channel->id,
                'branch_id' => $channel->branch_id,
                'public_code' => $this->publicCode(),
                'status' => CustomerQrOrder::STATUS_PENDING,
                'order_type' => $orderType,
                'customer_name' => trim((string) ($payload['customer_name'] ?? '')),
                'customer_phone' => trim((string) ($payload['customer_phone'] ?? '')) ?: null,
                'table_or_reference' => trim((string) ($payload['table_or_reference'] ?? '')) ?: null,
                'notes' => trim((string) ($payload['notes'] ?? '')) ?: null,
                'items' => $normalizedItems->values()->all(),
                'subtotal' => $subtotal,
                'service_fee' => $serviceFee,
                'total' => round($subtotal + $serviceFee, 2),
                'client_ip_hash' => $this->hashNullable($request->ip()),
                'user_agent_hash' => $this->hashNullable($request->userAgent()),
                'submitted_at' => now(),
            ]);

            $this->traces->recordModel(
                $order,
                'customer_qr_order_created',
                null,
                $channel->branch_id,
                ['public_code' => $order->public_code, 'order_type' => $order->order_type, 'total' => (float) $order->total],
                ['channel_id' => $channel->id],
                'public_qr',
            );

            return $order;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function publicStatus(CustomerQrChannel $channel, ?string $publicCode): ?array
    {
        $publicCode = trim((string) $publicCode);

        if ($publicCode === '') {
            return null;
        }

        $order = CustomerQrOrder::query()
            ->where('customer_qr_channel_id', $channel->id)
            ->where('public_code', $publicCode)
            ->first(['public_code', 'status', 'total', 'submitted_at', 'accepted_at', 'rejected_at', 'converted_at', 'prepared_at', 'ready_at', 'delivered_at']);

        if (! $order) {
            return null;
        }

        return [
            'public_code' => $order->public_code,
            'status' => $order->status,
            'label' => $this->statusLabel($order->status),
            'total' => 'Bs '.number_format((float) $order->total, 2, ',', '.'),
            'submitted_at' => $order->submitted_at?->format('d/m/Y H:i'),
            'updated_at' => collect([$order->delivered_at, $order->ready_at, $order->prepared_at, $order->converted_at, $order->rejected_at, $order->accepted_at, $order->submitted_at])
                ->filter()
                ->first()
                ?->format('d/m/Y H:i'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $profile = ActiveBusinessProfile::payload();

        return [
            'max_items_per_order' => max(1, (int) data_get($profile, 'customer_qr_ordering.max_items_per_order', 40)),
            'max_quantity_per_item' => max(1, (float) data_get($profile, 'customer_qr_ordering.max_quantity_per_item', 999)),
            'allowed_order_types' => (array) data_get($profile, 'customer_qr_ordering.allowed_order_types', ['pickup']),
            'requires_customer_name' => (bool) data_get($profile, 'customer_qr_ordering.requires_customer_name', true),
            'requires_customer_phone' => (bool) data_get($profile, 'customer_qr_ordering.requires_customer_phone', false),
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>> $items
     * @return Collection<int, Product>
     */
    private function productsFor(Collection $items): Collection
    {
        return Product::query()
            ->whereIn('id', $items->pluck('product_id')->map(fn ($id): int => (int) $id)->unique()->values())
            ->where('is_active', true)
            ->where('is_sellable', true)
            ->get(['id', 'name', 'sku', 'barcode', 'inventory_tracking_mode', 'base_unit', 'item_type', 'is_inventory_item', 'sale_price'])
            ->keyBy('id');
    }

    /**
     * @param Collection<int, array<string, mixed>> $items
     * @param Collection<int, Product> $products
     * @return Collection<int, array<string, mixed>>
     */
    private function normalizeItems(Collection $items, Collection $products, float $maxQuantity): Collection
    {
        return $items->map(function (array $item) use ($products, $maxQuantity): array {
            $product = $products->get((int) $item['product_id']);

            if (! $product) {
                throw ValidationException::withMessages([
                    'items' => 'Uno de los productos o servicios ya no esta disponible.',
                ]);
            }

            $quantity = round(min(max((float) $item['quantity'], 0.001), $maxQuantity), 3);
            $unitPrice = round((float) $product->sale_price, 2);

            return [
                'product_id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'base_unit' => $product->base_unit,
                'item_type' => $product->item_type,
                'is_inventory_item' => (bool) $product->is_inventory_item,
                'inventory_tracking_mode' => $product->inventory_tracking_mode,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'subtotal' => round($quantity * $unitPrice, 2),
                'notes' => trim((string) ($item['notes'] ?? '')) ?: null,
            ];
        });
    }

    /**
     * @param Collection<int, array<string, mixed>> $items
     */
    private function ensureStockAvailable(CustomerQrChannel $channel, Collection $items): void
    {
        if (! $channel->branch_id) {
            return;
        }

        $inventoryItems = $items
            ->filter(fn (array $item): bool => (bool) ($item['is_inventory_item'] ?? false))
            ->groupBy('product_id')
            ->map(fn (Collection $rows): float => round($rows->sum(fn (array $row): float => (float) $row['quantity']), 3));

        if ($inventoryItems->isEmpty()) {
            return;
        }

        $stocks = ProductBranchStock::query()
            ->where('branch_id', $channel->branch_id)
            ->whereIn('product_id', $inventoryItems->keys()->all())
            ->get(['product_id', 'available_meters', 'reserved_meters', 'is_enabled'])
            ->keyBy('product_id');

        foreach ($inventoryItems as $productId => $quantity) {
            $stock = $stocks->get((int) $productId);
            $free = $stock ? round((float) $stock->available_meters - (float) $stock->reserved_meters, 3) : 0.0;

            if (! $stock || ! $stock->is_enabled || $free < $quantity) {
                throw ValidationException::withMessages([
                    'items' => 'Uno de los productos no tiene stock libre suficiente en esta sucursal.',
                ]);
            }
        }
    }

    private function imageUrl(Product $product): ?string
    {
        $image = $product->primaryImage;

        if (! $image) {
            return null;
        }

        if (filled($image->url)) {
            return $image->url;
        }

        return filled($image->path) ? Storage::url($image->path) : null;
    }

    private function statusLabel(string $status): string
    {
        return [
            CustomerQrOrder::STATUS_PENDING => 'Recibido',
            CustomerQrOrder::STATUS_ACCEPTED => 'Aceptado',
            CustomerQrOrder::STATUS_PREPARING => 'En preparacion',
            CustomerQrOrder::STATUS_READY => 'Listo',
            CustomerQrOrder::STATUS_DELIVERED => 'Entregado',
            CustomerQrOrder::STATUS_REJECTED => 'Rechazado',
            CustomerQrOrder::STATUS_CONVERTED => 'Convertido',
        ][$status] ?? 'En revision';
    }

    private function publicCode(): string
    {
        do {
            $code = 'QR-'.now()->format('ymd').'-'.Str::upper(Str::random(6));
        } while (CustomerQrOrder::query()->where('public_code', $code)->exists());

        return $code;
    }

    private function hashNullable(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
