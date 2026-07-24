<?php

namespace App\Modules\Billing\Services;

use App\Modules\Sales\Models\Sale;
use App\Modules\SystemSuperadmin\Services\ActiveBusinessProfile;

class BillingWorkflowPolicy
{
    private function billing(): array
    {
        return ActiveBusinessProfile::payload()['billing'] ?? [];
    }

    public function enabled(): bool
    {
        return ActiveBusinessProfile::enabled('billing')
            && (($this->billing()['invoice_flow'] ?? 'billing_disabled') !== 'billing_disabled');
    }

    public function invoiceFlow(): string
    {
        return (string) ($this->billing()['invoice_flow'] ?? 'sale_note_then_invoice');
    }

    public function issueTiming(): string
    {
        return (string) ($this->billing()['issue_timing'] ?? 'manual');
    }

    public function requiresProductMapping(): bool
    {
        return (bool) ($this->billing()['require_product_mapping'] ?? true);
    }

    public function requireCustomerTaxData(): bool
    {
        return (bool) ($this->billing()['require_customer_tax_data'] ?? true);
    }

    public function blockSaleIfInvoiceFails(): bool
    {
        return (bool) ($this->billing()['block_sale_if_invoice_fails'] ?? true);
    }

    public function allowTemporaryReceipt(): bool
    {
        return (bool) ($this->billing()['allow_temporary_receipt'] ?? true);
    }

    public function autoRequestCufd(): bool
    {
        return (bool) ($this->billing()['auto_request_cufd'] ?? true);
    }

    public function canIssueForSale(Sale $sale): bool
    {
        if (! $this->enabled() || $sale->document_type !== 'sale_note' || $sale->status === 'void') {
            return false;
        }

        return ! $sale->siatInvoices()
            ->whereIn('status', ['pending', 'validated'])
            ->exists();
    }

    public function shouldShowManualButton(Sale $sale): bool
    {
        return $this->canIssueForSale($sale)
            && in_array($this->invoiceFlow(), ['sale_note_then_invoice', 'choose_per_sale'], true)
            && $this->issueTiming() === 'manual';
    }

    public function shouldAutoIssueAfterSaleCreated(Sale $sale): bool
    {
        if (! $this->canIssueForSale($sale)) {
            return false;
        }

        return in_array($this->issueTiming(), ['automatic_on_sale_note', 'automatic_direct'], true)
            || $this->invoiceFlow() === 'direct_invoice';
    }

    public function shouldAutoIssueAfterQuotationConversion(Sale $sale): bool
    {
        if (! $this->canIssueForSale($sale)) {
            return false;
        }

        return $this->invoiceFlow() === 'quote_sale_note_invoice'
            || $this->issueTiming() === 'automatic_after_quote_conversion';
    }

    public function scenarioLabel(): string
    {
        return [
            'quote_sale_note_invoice' => 'Cotizacion -> nota de venta -> factura',
            'sale_note_then_invoice' => 'Nota de venta -> factura',
            'direct_invoice' => 'Venta directa con factura inmediata',
            'choose_per_sale' => 'Escoger en cada venta si se factura',
            'billing_disabled' => 'Sin facturacion fiscal',
        ][$this->invoiceFlow()] ?? $this->invoiceFlow();
    }

    public function summary(): array
    {
        return [
            'enabled' => $this->enabled(),
            'invoiceFlow' => $this->invoiceFlow(),
            'issueTiming' => $this->issueTiming(),
            'scenarioLabel' => $this->scenarioLabel(),
            'requiresProductMapping' => $this->requiresProductMapping(),
            'requireCustomerTaxData' => $this->requireCustomerTaxData(),
            'blockSaleIfInvoiceFails' => $this->blockSaleIfInvoiceFails(),
            'allowTemporaryReceipt' => $this->allowTemporaryReceipt(),
            'autoRequestCufd' => $this->autoRequestCufd(),
        ];
    }
}
