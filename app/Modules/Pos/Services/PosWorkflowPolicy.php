<?php

namespace App\Modules\Pos\Services;

use App\Modules\SystemSuperadmin\Services\ActiveBusinessProfile;

class PosWorkflowPolicy
{
    private array $payload;

    public function __construct()
    {
        $this->payload = ActiveBusinessProfile::payload();
    }

    public function mode(): string
    {
        $workflow = (string) data_get($this->payload, 'sales.workflow', 'pos');
        $catalogMode = (string) data_get($this->payload, 'products.catalog_mode', 'mixed_inventory');

        return match ($workflow) {
            'restaurant_table' => 'restaurant_table',
            'restaurant_counter' => 'restaurant_counter',
            'service_sale' => 'services',
            'pos' => $catalogMode === 'barcode_retail' ? 'supermarket' : 'retail',
            default => $catalogMode === 'services' ? 'services' : 'retail',
        };
    }

    public function modeLabel(): string
    {
        return match ($this->mode()) {
            'restaurant_table' => 'POS restaurante con mesas',
            'restaurant_counter' => 'POS restaurante mostrador',
            'services' => 'POS de servicios',
            'supermarket' => 'POS supermercado',
            default => 'POS retail',
        };
    }

    public function scannerMode(): string
    {
        return (string) data_get($this->payload, 'pos.scanner_mode', 'optional');
    }

    public function offlineMode(): string
    {
        return (string) data_get($this->payload, 'pos.offline_mode', 'disabled');
    }

    public function customerPrompt(): string
    {
        return (string) data_get($this->payload, 'pos.customer_prompt', 'optional');
    }

    public function paymentFlow(): string
    {
        return (string) data_get($this->payload, 'pos.payment_flow', 'single_or_mixed');
    }

    public function cartMergeRule(): string
    {
        return (string) data_get($this->payload, 'pos.cart_merge_rule', 'same_product_and_unit');
    }

    public function allowedItemTypes(): array
    {
        $types = data_get($this->payload, 'products.item_types', ['physical']);

        if (! is_array($types) || $types === []) {
            return ['physical'];
        }

        return array_values(array_unique(array_filter($types, fn ($type) => is_string($type) && $type !== '')));
    }

    public function usesTables(): bool
    {
        return (bool) data_get($this->payload, 'capabilities.uses_tables', false)
            || in_array($this->mode(), ['restaurant_table'], true);
    }

    public function usesWaiters(): bool
    {
        return (bool) data_get($this->payload, 'capabilities.uses_waiters', false);
    }

    public function usesKitchen(): bool
    {
        return (bool) data_get($this->payload, 'capabilities.uses_kitchen_orders', false)
            || in_array($this->mode(), ['restaurant_table', 'restaurant_counter'], true);
    }

    public function usesTips(): bool
    {
        return (bool) data_get($this->payload, 'capabilities.uses_tips', false)
            || (string) data_get($this->payload, 'restaurant.tips_mode', 'disabled') !== 'disabled';
    }

    public function usesSplitPayments(): bool
    {
        return (bool) data_get($this->payload, 'capabilities.uses_split_payments', false)
            || $this->paymentFlow() === 'single_or_mixed';
    }

    public function usesDelivery(): bool
    {
        return (bool) data_get($this->payload, 'capabilities.uses_delivery', false);
    }

    public function usesImages(): bool
    {
        return (bool) data_get($this->payload, 'products.images_enabled', false);
    }

    public function usesVariants(): bool
    {
        return (bool) data_get($this->payload, 'products.variants_enabled', false);
    }

    public function supportsServices(): bool
    {
        return (bool) data_get($this->payload, 'capabilities.uses_services', false)
            || in_array('service', $this->allowedItemTypes(), true)
            || $this->mode() === 'services';
    }

    public function requiresCustomer(): bool
    {
        return (bool) data_get($this->payload, 'capabilities.requires_customer', false)
            || in_array(data_get($this->payload, 'sales.customer_mode'), ['required'], true)
            || $this->customerPrompt() === 'required';
    }

    public function manualSelectionAllowed(): bool
    {
        return $this->scannerMode() !== 'required';
    }

    public function summary(): array
    {
        return [
            'mode' => $this->mode(),
            'modeLabel' => $this->modeLabel(),
            'scannerMode' => $this->scannerMode(),
            'offlineMode' => $this->offlineMode(),
            'customerPrompt' => $this->customerPrompt(),
            'paymentFlow' => $this->paymentFlow(),
            'cartMergeRule' => $this->cartMergeRule(),
            'allowedItemTypes' => $this->allowedItemTypes(),
            'usesTables' => $this->usesTables(),
            'usesWaiters' => $this->usesWaiters(),
            'usesKitchen' => $this->usesKitchen(),
            'usesTips' => $this->usesTips(),
            'usesSplitPayments' => $this->usesSplitPayments(),
            'usesDelivery' => $this->usesDelivery(),
            'usesImages' => $this->usesImages(),
            'usesVariants' => $this->usesVariants(),
            'supportsServices' => $this->supportsServices(),
            'requiresCustomer' => $this->requiresCustomer(),
            'manualSelectionAllowed' => $this->manualSelectionAllowed(),
        ];
    }
}
