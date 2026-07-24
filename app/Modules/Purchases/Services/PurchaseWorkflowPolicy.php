<?php

namespace App\Modules\Purchases\Services;

use App\Modules\Inventory\Services\ProductWorkflowPolicy;
use App\Modules\SystemSuperadmin\Services\ActiveBusinessProfile;

class PurchaseWorkflowPolicy
{
    public function __construct(private readonly ProductWorkflowPolicy $products)
    {
    }

    public function workflow(): string
    {
        return (string) (ActiveBusinessProfile::payload()['purchases']['workflow'] ?? 'standard_purchase');
    }

    public function barcodeEntryEnabled(): bool
    {
        return (bool) (ActiveBusinessProfile::payload()['purchases']['barcode_entry'] ?? false)
            || ActiveBusinessProfile::enabled('quick_purchases');
    }

    public function allowCreateProductFromPurchase(): bool
    {
        return (bool) (ActiveBusinessProfile::payload()['purchases']['allow_create_product'] ?? true)
            && $this->products->canCreateFromPurchase();
    }

    public function supplierMode(): string
    {
        return (string) (ActiveBusinessProfile::payload()['purchases']['supplier_mode'] ?? 'optional');
    }

    public function supplierRequired(): bool
    {
        return $this->supplierMode() === 'required';
    }

    public function supplierHidden(): bool
    {
        return $this->supplierMode() === 'hidden';
    }

    public function registerExpenseWhenPaid(): bool
    {
        return (bool) (ActiveBusinessProfile::payload()['purchases']['register_expense_when_paid'] ?? true);
    }

    public function summary(): array
    {
        return [
            'mode' => $this->workflow(),
            'barcodeEntryEnabled' => $this->barcodeEntryEnabled(),
            'allowCreateProduct' => $this->allowCreateProductFromPurchase(),
            'supplierMode' => $this->supplierMode(),
            'supplierRequired' => $this->supplierRequired(),
            'supplierHidden' => $this->supplierHidden(),
            'registerExpenseWhenPaid' => $this->registerExpenseWhenPaid(),
            'productPolicy' => $this->products->summary(),
        ];
    }
}
