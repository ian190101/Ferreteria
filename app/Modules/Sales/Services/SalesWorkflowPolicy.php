<?php

namespace App\Modules\Sales\Services;

use App\Modules\SystemSuperadmin\Services\ActiveBusinessProfile;

class SalesWorkflowPolicy
{
    public function workflow(): string
    {
        return (string) (ActiveBusinessProfile::payload()['sales']['workflow'] ?? 'quotation_to_sale_note');
    }

    public function quotationMode(): string
    {
        return (string) (ActiveBusinessProfile::payload()['sales']['quotation_mode'] ?? 'required');
    }

    public function documentMain(): string
    {
        return (string) (ActiveBusinessProfile::payload()['sales']['document_main'] ?? 'sale_note');
    }

    public function inventoryDiscountTiming(): string
    {
        return (string) (ActiveBusinessProfile::payload()['sales']['inventory_discount_timing'] ?? 'sale_note');
    }

    public function customerRequired(): bool
    {
        return $this->customerMode() === 'required';
    }

    public function customerMode(): string
    {
        return (string) (ActiveBusinessProfile::payload()['sales']['customer_mode'] ?? ((ActiveBusinessProfile::payload()['sales']['customer_required'] ?? true) ? 'required' : 'optional'));
    }

    public function customerHidden(): bool
    {
        return $this->customerMode() === 'hidden';
    }

    public function allowsNegativeStock(): bool
    {
        return (bool) (ActiveBusinessProfile::payload()['sales']['allow_negative_stock'] ?? false);
    }

    public function allowsDocumentType(string $documentType): bool
    {
        if ($documentType === 'quotation') {
            return $this->quotationMode() !== 'disabled' && ActiveBusinessProfile::enabled('quotes');
        }

        if ($documentType === 'sale_note') {
            return ActiveBusinessProfile::enabled('sales_notes');
        }

        return false;
    }

    public function requiresSourceQuotationForSaleNote(): bool
    {
        return $this->workflow() === 'quotation_to_sale_note'
            && $this->quotationMode() === 'required';
    }

    public function shouldDiscountInventoryOnSaleNote(): bool
    {
        return $this->inventoryDiscountTiming() === 'sale_note';
    }

    public function shouldDiscountInventoryOnPayment(): bool
    {
        return $this->inventoryDiscountTiming() === 'payment';
    }

    public function shouldDiscountInventoryOnDelivery(): bool
    {
        return $this->inventoryDiscountTiming() === 'delivery';
    }

    public function summary(): array
    {
        return [
            'mode' => $this->workflow(),
            'quotationMode' => $this->quotationMode(),
            'documentMain' => $this->documentMain(),
            'inventoryDiscountTiming' => $this->inventoryDiscountTiming(),
            'customerRequired' => $this->customerRequired(),
            'customerMode' => $this->customerMode(),
            'customerHidden' => $this->customerHidden(),
            'allowsNegativeStock' => $this->allowsNegativeStock(),
            'canCreateQuotation' => $this->allowsDocumentType('quotation'),
            'canCreateSaleNote' => $this->allowsDocumentType('sale_note'),
            'requiresSourceQuotationForSaleNote' => $this->requiresSourceQuotationForSaleNote(),
        ];
    }

    public function documentTypeError(string $documentType): string
    {
        if ($documentType === 'quotation') {
            return 'La configuracion empresarial actual no permite crear cotizaciones.';
        }

        return 'La configuracion empresarial actual no permite crear notas de venta.';
    }
}
