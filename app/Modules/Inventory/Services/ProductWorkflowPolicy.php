<?php

namespace App\Modules\Inventory\Services;

use App\Modules\SystemSuperadmin\Services\ActiveBusinessProfile;

class ProductWorkflowPolicy
{
    public function catalogMode(): string
    {
        return (string) (ActiveBusinessProfile::payload()['products']['catalog_mode'] ?? 'mixed_inventory');
    }

    public function barcodeRequired(): bool
    {
        return (bool) (ActiveBusinessProfile::payload()['products']['barcode_required'] ?? false);
    }

    public function unitEquivalencesEnabled(): bool
    {
        return (bool) (ActiveBusinessProfile::payload()['products']['unit_equivalences'] ?? true);
    }

    public function allowServiceItems(): bool
    {
        return (bool) (ActiveBusinessProfile::payload()['products']['allow_service_items'] ?? false);
    }

    public function creationContext(): string
    {
        return (string) (ActiveBusinessProfile::payload()['products']['creation_context'] ?? 'inventory_and_purchase');
    }

    public function canCreateFromPurchase(): bool
    {
        return $this->creationContext() === 'inventory_and_purchase';
    }

    public function canCreateFromInventory(): bool
    {
        return in_array($this->creationContext(), ['inventory_only', 'inventory_and_purchase', 'restricted'], true);
    }

    public function summary(): array
    {
        return [
            'catalogMode' => $this->catalogMode(),
            'barcodeRequired' => $this->barcodeRequired(),
            'unitEquivalencesEnabled' => $this->unitEquivalencesEnabled(),
            'allowServiceItems' => $this->allowServiceItems(),
            'creationContext' => $this->creationContext(),
            'canCreateFromPurchase' => $this->canCreateFromPurchase(),
            'canCreateFromInventory' => $this->canCreateFromInventory(),
        ];
    }
}
