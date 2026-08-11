<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Models\User;
use App\Modules\Reservations\Models\BusinessReservation;
use App\Modules\Restaurant\Models\KitchenOrder;
use App\Modules\Sales\Events\SaleNoteIssued;
use App\Modules\Sales\Models\Currency;
use App\Modules\Sales\Models\DocumentSequence;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleType;
use App\Modules\ServiceOrders\Models\ServiceOrder;
use App\Modules\SystemSuperadmin\Models\CustomerQrOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerQrOrderConversionService
{
    public const TYPE_SALE = 'sale';
    public const TYPE_QUOTATION = 'quotation';
    public const TYPE_KITCHEN_ORDER = 'kitchen_order';
    public const TYPE_SERVICE_ORDER = 'service_order';
    public const TYPE_RESERVATION = 'reservation';

    public function __construct(
        private readonly CustomerQrOrderInventoryService $inventory,
        private readonly OperationalTraceService $traces,
    ) {
    }

    /**
     * @return array{type: string, id: int, label: string, url: string|null}
     */
    public function convert(CustomerQrOrder $order, string $targetType, User $user): array
    {
        $this->ensureCanConvert($order, $targetType);

        $converted = DB::transaction(function () use ($order, $targetType, $user): Model {
            return match ($targetType) {
                self::TYPE_SALE => $this->toSale($order, $user, 'sale_note'),
                self::TYPE_QUOTATION => $this->toSale($order, $user, 'quotation'),
                self::TYPE_KITCHEN_ORDER => $this->toKitchenOrder($order, $user),
                self::TYPE_SERVICE_ORDER => $this->toServiceOrder($order, $user),
                self::TYPE_RESERVATION => $this->toReservation($order, $user),
                default => throw ValidationException::withMessages(['target_type' => 'Tipo de conversion no permitido.']),
            };
        });

        $order->refresh();

        return [
            'type' => $targetType,
            'id' => (int) $converted->getKey(),
            'label' => $this->labelFor($targetType, $converted),
            'url' => $this->urlFor($targetType, $converted),
        ];
    }

    private function ensureCanConvert(CustomerQrOrder $order, string $targetType): void
    {
        if ($order->status === CustomerQrOrder::STATUS_REJECTED) {
            throw ValidationException::withMessages(['order' => 'No se puede convertir un pedido rechazado.']);
        }

        if ($order->converted_at) {
            throw ValidationException::withMessages(['order' => 'Este pedido QR ya fue convertido.']);
        }

        $checks = [
            self::TYPE_SALE => ActiveBusinessProfile::enabled('sales_notes') && (ActiveBusinessProfile::capable('allows_direct_sale') || ActiveBusinessProfile::capable('uses_pos')),
            self::TYPE_QUOTATION => ActiveBusinessProfile::enabled('quotes') && ActiveBusinessProfile::capable('allows_quote_to_sale'),
            self::TYPE_KITCHEN_ORDER => ActiveBusinessProfile::enabled('kitchen_orders'),
            self::TYPE_SERVICE_ORDER => ActiveBusinessProfile::enabled('service_orders') || ActiveBusinessProfile::enabled('services'),
            self::TYPE_RESERVATION => ActiveBusinessProfile::enabled('reservations') || ActiveBusinessProfile::capable('uses_reservations'),
        ];

        if (! ($checks[$targetType] ?? false)) {
            throw ValidationException::withMessages([
                'target_type' => 'El perfil actual no habilita este destino de conversion.',
            ]);
        }
    }

    private function toSale(CustomerQrOrder $order, User $user, string $documentType): Sale
    {
        $currency = Currency::query()->where('is_base', true)->where('is_active', true)->first()
            ?? Currency::query()->firstOrCreate(['code' => 'BOB'], [
                'name' => 'Bolivianos',
                'symbol' => 'Bs',
                'exchange_rate_to_bob' => 1,
                'is_base' => true,
                'is_active' => true,
            ]);

        $saleType = SaleType::query()->where('is_active', true)->first()
            ?? SaleType::query()->firstOrCreate(['name' => 'Ocasionales'], ['is_active' => true]);

        $sale = Sale::query()->create([
            'branch_id' => $order->branch_id ?? $user->branch_id,
            'user_id' => $user->id,
            'sale_type_id' => $saleType->id,
            'currency_id' => $currency->id,
            'receipt_number' => $this->nextReceiptNumber((int) ($order->branch_id ?? $user->branch_id), $documentType),
            'document_type' => $documentType,
            'customer_name' => $order->customer_name ?: 'Cliente QR',
            'customer_contact' => $order->customer_phone,
            'sold_at' => now(),
            'exchange_rate_to_bob' => $currency->exchange_rate_to_bob,
            'subtotal' => $order->subtotal,
            'discount_total' => 0,
            'advance_percentage' => 0,
            'advance_amount' => 0,
            'balance_due' => $documentType === 'sale_note' ? $order->total : 0,
            'total' => $order->total,
            'status' => $documentType === 'quotation' ? 'quoted' : 'issued',
            'requires_delivery' => $order->order_type === 'delivery',
            'terms' => 'Generado desde pedido QR '.$order->public_code,
            'internal_notes' => trim(implode("\n", array_filter([$order->notes, 'Canal QR: '.$order->channel?->name]))),
        ]);

        $sale->items()->createMany($this->saleItems($order));

        if ($documentType === 'sale_note') {
            $this->inventory->consumeForSale($order, (int) $sale->id);
            event(new SaleNoteIssued((int) $sale->id, (int) $user->id));
        }

        $this->markConverted($order, $user, $documentType === 'sale_note' ? self::TYPE_SALE : self::TYPE_QUOTATION, (int) $sale->id);

        return $sale;
    }

    private function toKitchenOrder(CustomerQrOrder $order, User $user): KitchenOrder
    {
        $kitchenOrder = KitchenOrder::query()->create([
            'branch_id' => $order->branch_id ?? $user->branch_id,
            'waiter_user_id' => $user->id,
            'order_number' => 'CMD-'.now()->format('ymdHis').'-'.$order->id,
            'customer_label' => $order->table_or_reference ?: ($order->customer_name ?: 'Pedido QR'),
            'channel' => $order->order_type,
            'preparation_area' => data_get($order->channel?->settings, 'preparation_area', 'cocina'),
            'status' => KitchenOrder::STATUS_SENT,
            'subtotal' => $order->subtotal,
            'sent_at' => now(),
            'notes' => trim('Pedido QR '.$order->public_code."\n".$order->notes),
        ]);

        $kitchenOrder->items()->createMany($this->kitchenItems($order));
        $this->markConverted($order, $user, self::TYPE_KITCHEN_ORDER, (int) $kitchenOrder->id);

        return $kitchenOrder;
    }

    private function toServiceOrder(CustomerQrOrder $order, User $user): ServiceOrder
    {
        $serviceOrder = ServiceOrder::query()->create([
            'branch_id' => $order->branch_id ?? $user->branch_id,
            'user_id' => $user->id,
            'order_number' => 'OS-'.now()->format('ymdHis').'-'.$order->id,
            'customer_name' => $order->customer_name ?: 'Cliente QR',
            'customer_phone' => $order->customer_phone,
            'title' => 'Pedido QR '.$order->public_code,
            'service_type' => $order->order_type,
            'status' => ServiceOrder::STATUS_PENDING,
            'materials_amount' => $order->subtotal,
            'total_amount' => $order->total,
            'notes' => $order->notes,
        ]);

        $serviceOrder->items()->createMany($this->serviceItems($order));
        $this->markConverted($order, $user, self::TYPE_SERVICE_ORDER, (int) $serviceOrder->id);

        return $serviceOrder;
    }

    private function toReservation(CustomerQrOrder $order, User $user): BusinessReservation
    {
        $start = now()->addHour()->startOfMinute();

        $reservation = BusinessReservation::query()->create([
            'branch_id' => $order->branch_id ?? $user->branch_id,
            'user_id' => $user->id,
            'reservation_number' => 'RES-'.now()->format('ymdHis').'-'.$order->id,
            'type' => BusinessReservation::TYPE_RESERVATION,
            'appointment_kind' => $order->order_type,
            'channel' => 'qr',
            'status' => BusinessReservation::STATUS_PENDING,
            'confirmation_status' => BusinessReservation::CONFIRMATION_PENDING,
            'customer_name' => $order->customer_name ?: 'Cliente QR',
            'customer_phone' => $order->customer_phone,
            'title' => 'Reserva desde pedido QR '.$order->public_code,
            'start_at' => $start,
            'end_at' => (clone $start)->addMinutes((int) data_get(ActiveBusinessProfile::payload(), 'customer_qr_ordering.default_reservation_minutes', 60)),
            'amount' => $order->subtotal,
            'total_amount' => $order->total,
            'notes' => $order->notes,
            'metadata' => ['customer_qr_order_id' => $order->id, 'public_code' => $order->public_code],
        ]);

        $reservation->items()->createMany($this->reservationItems($order));
        $this->markConverted($order, $user, self::TYPE_RESERVATION, (int) $reservation->id);

        return $reservation;
    }

    private function markConverted(CustomerQrOrder $order, User $user, string $targetType, int $targetId): void
    {
        $order->update([
            'status' => CustomerQrOrder::STATUS_CONVERTED,
            'converted_by_id' => $user->id,
            'converted_to_type' => $targetType,
            'converted_to_id' => $targetId,
            'converted_at' => now(),
        ]);

        $this->traces->recordModel(
            $order,
            'customer_qr_order_converted',
            $user,
            $order->branch_id,
            ['target_type' => $targetType, 'target_id' => $targetId],
            ['public_code' => $order->public_code],
            'qr_operations',
        );
    }

    private function nextReceiptNumber(int $branchId, string $documentType): string
    {
        $sequence = DocumentSequence::query()
            ->where('branch_id', $branchId)
            ->where('document_type', $documentType)
            ->lockForUpdate()
            ->first();

        if (! $sequence) {
            $sequence = DocumentSequence::query()->create([
                'branch_id' => $branchId,
                'document_type' => $documentType,
                'name' => $documentType === 'quotation' ? 'Cotizaciones' : 'Notas de venta',
                'prefix' => $documentType === 'quotation' ? 'COT-' : 'NV-',
                'next_number' => 1,
                'padding' => 6,
                'is_active' => true,
            ]);
            $sequence = DocumentSequence::query()->whereKey($sequence->id)->lockForUpdate()->firstOrFail();
        }

        $number = $sequence->preview();
        $sequence->increment('next_number');

        return $number;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function saleItems(CustomerQrOrder $order): array
    {
        return collect($order->items ?? [])->map(fn (array $item): array => [
            'product_id' => $item['product_id'],
            'description' => $item['name'],
            'unit_label' => $item['base_unit'] ?? 'un',
            'display_quantity' => $item['quantity'],
            'display_unit_label' => $item['base_unit'] ?? 'un',
            'item_attributes' => ['source' => 'customer_qr_order', 'public_code' => $order->public_code],
            'calculation_mode' => 'unit',
            'meters' => $item['quantity'],
            'unit_price' => $item['unit_price'],
            'discount_amount' => 0,
            'total' => $item['subtotal'],
        ])->values()->all();
    }

    private function kitchenItems(CustomerQrOrder $order): array
    {
        return collect($order->items ?? [])->map(fn (array $item): array => [
            'product_id' => $item['product_id'],
            'quantity' => $item['quantity'],
            'unit_label' => $item['base_unit'] ?? 'un',
            'unit_price' => $item['unit_price'],
            'total' => $item['subtotal'],
            'status' => KitchenOrder::STATUS_SENT,
            'notes' => $item['notes'] ?? null,
        ])->values()->all();
    }

    private function serviceItems(CustomerQrOrder $order): array
    {
        return collect($order->items ?? [])->map(fn (array $item): array => [
            'product_id' => $item['product_id'],
            'quantity' => $item['quantity'],
            'unit_label' => $item['base_unit'] ?? 'un',
            'unit_cost' => 0,
            'unit_price' => $item['unit_price'],
            'subtotal' => $item['subtotal'],
            'discount_inventory' => (bool) ($item['is_inventory_item'] ?? false),
            'notes' => $item['notes'] ?? null,
        ])->values()->all();
    }

    private function reservationItems(CustomerQrOrder $order): array
    {
        return collect($order->items ?? [])->map(fn (array $item): array => [
            'product_id' => $item['product_id'],
            'description' => $item['name'],
            'quantity' => $item['quantity'],
            'unit_label' => $item['base_unit'] ?? 'un',
            'unit_price' => $item['unit_price'],
            'subtotal' => $item['subtotal'],
            'notes' => $item['notes'] ?? null,
        ])->values()->all();
    }

    private function labelFor(string $targetType, Model $model): string
    {
        return match ($targetType) {
            self::TYPE_SALE, self::TYPE_QUOTATION => (string) $model->receipt_number,
            self::TYPE_KITCHEN_ORDER => (string) $model->order_number,
            self::TYPE_SERVICE_ORDER => (string) $model->order_number,
            self::TYPE_RESERVATION => (string) $model->reservation_number,
            default => '#'.$model->getKey(),
        };
    }

    private function urlFor(string $targetType, Model $model): ?string
    {
        return match ($targetType) {
            self::TYPE_SALE, self::TYPE_QUOTATION => route('sales.show', $model->getKey()),
            self::TYPE_SERVICE_ORDER => route('service-orders.index', ['search' => $model->order_number]),
            self::TYPE_RESERVATION => route('reservations.index', ['search' => $model->reservation_number]),
            self::TYPE_KITCHEN_ORDER => route('restaurant.index'),
            default => null,
        };
    }
}
