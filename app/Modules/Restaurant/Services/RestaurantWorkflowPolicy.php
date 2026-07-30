<?php

namespace App\Modules\Restaurant\Services;

use App\Modules\SystemSuperadmin\Services\ActiveBusinessProfile;

class RestaurantWorkflowPolicy
{
    private array $payload;

    public function __construct()
    {
        $this->payload = ActiveBusinessProfile::payload();
    }

    public function enabled(): bool
    {
        return $this->tablesEnabled() || $this->kitchenEnabled() || $this->recipesEnabled();
    }

    public function mode(): string
    {
        return (string) data_get($this->payload, 'restaurant.mode', 'disabled');
    }

    public function tablesEnabled(): bool
    {
        return ActiveBusinessProfile::enabled('restaurant_tables')
            || (bool) data_get($this->payload, 'restaurant.tables_enabled', false);
    }

    public function kitchenEnabled(): bool
    {
        return ActiveBusinessProfile::enabled('kitchen_orders')
            || (bool) data_get($this->payload, 'capabilities.uses_kitchen_orders', false);
    }

    public function recipesEnabled(): bool
    {
        return ActiveBusinessProfile::enabled('recipes')
            || (bool) data_get($this->payload, 'capabilities.uses_recipes', false);
    }

    public function waitersEnabled(): bool
    {
        return (bool) data_get($this->payload, 'restaurant.waiters_enabled', false)
            || (bool) data_get($this->payload, 'capabilities.uses_waiters', false);
    }

    public function tipsMode(): string
    {
        return (string) data_get($this->payload, 'restaurant.tips_mode', 'disabled');
    }

    public function splitBillEnabled(): bool
    {
        return (bool) data_get($this->payload, 'restaurant.split_bill_enabled', false)
            || (bool) data_get($this->payload, 'capabilities.uses_split_payments', false);
    }

    public function discountInventoryAt(): string
    {
        return (string) data_get($this->payload, 'restaurant.discount_inventory_at', 'sale_close');
    }

    public function shouldDiscountOnKitchenOrder(): bool
    {
        return $this->recipesEnabled() && $this->discountInventoryAt() === 'kitchen_order';
    }

    public function allowsNegativeStock(): bool
    {
        return (bool) data_get($this->payload, 'sales.allow_negative_stock', false);
    }

    public function kitchenAreas(): array
    {
        $areas = data_get($this->payload, 'restaurant.kitchen_areas', []);

        if (! is_array($areas) || $areas === []) {
            return ['Cocina', 'Barra'];
        }

        return array_values(array_filter($areas, fn ($area) => is_string($area) && $area !== ''));
    }

    public function summary(): array
    {
        return [
            'enabled' => $this->enabled(),
            'mode' => $this->mode(),
            'tablesEnabled' => $this->tablesEnabled(),
            'kitchenEnabled' => $this->kitchenEnabled(),
            'recipesEnabled' => $this->recipesEnabled(),
            'waitersEnabled' => $this->waitersEnabled(),
            'tipsMode' => $this->tipsMode(),
            'splitBillEnabled' => $this->splitBillEnabled(),
            'discountInventoryAt' => $this->discountInventoryAt(),
            'allowsNegativeStock' => $this->allowsNegativeStock(),
            'kitchenAreas' => $this->kitchenAreas(),
        ];
    }
}
