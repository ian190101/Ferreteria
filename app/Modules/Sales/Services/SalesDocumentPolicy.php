<?php

namespace App\Modules\Sales\Services;

use App\Modules\SystemSuperadmin\Services\ActiveBusinessProfile;

class SalesDocumentPolicy
{
    private const COLUMN_MAP = [
        'description' => 'item_description',
        'model' => 'item_attribute_modelo',
        'color' => 'item_attribute_color',
        'width' => 'item_attribute_ancho',
        'length' => 'item_attribute_largo',
        'quantity' => 'item_quantity',
        'unit' => 'item_unit',
        'base' => 'item_base',
        'price' => 'item_price',
        'subtotal' => 'item_subtotal',
    ];

    public function quotationLabel(): string
    {
        return $this->salesValue('quotation_label', 'Cotizacion');
    }

    public function saleNoteLabel(): string
    {
        return $this->salesValue('sale_note_label', 'Nota de venta');
    }

    public function ticketLabel(): string
    {
        return $this->salesValue('ticket_label', 'Ticket POS');
    }

    public function documentLabel(string $documentType, ?string $documentMain = null): string
    {
        if ($documentType === 'quotation') {
            return $this->quotationLabel();
        }

        return $documentMain === 'ticket' ? $this->ticketLabel() : $this->saleNoteLabel();
    }

    public function defaultTerms(): string
    {
        return $this->salesValue('default_terms', 'NOTA: NO SE ACEPTAN CAMBIOS NI DEVOLUCIONES.');
    }

    public function defaultTermsFor(string $documentType, ?string $documentMain = null): string
    {
        $key = $documentType === 'quotation'
            ? 'quotation'
            : ($documentMain === 'ticket' ? 'ticket' : 'sale_note');
        $value = ActiveBusinessProfile::payload()['sales']['terms_by_document'][$key] ?? null;

        return filled($value) ? (string) $value : $this->defaultTerms();
    }

    public function visibleTemplateColumns(): array
    {
        $columns = ActiveBusinessProfile::payload()['sales']['visible_columns'] ?? [];

        return collect($columns)
            ->map(fn (string $column) => self::COLUMN_MAP[$column] ?? null)
            ->filter()
            ->prepend('item_number')
            ->unique()
            ->values()
            ->all();
    }

    public function allowedPaymentMethodCodes(string $flow = 'sales'): array
    {
        $sales = ActiveBusinessProfile::payload()['sales'] ?? [];
        $codes = $sales['payment_methods_by_flow'][$flow] ?? $sales['allowed_payment_methods'] ?? ['cash', 'qr', 'transfer'];

        return collect($codes)
            ->map(fn ($code) => strtolower((string) $code))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function isPaymentMethodAllowed(?string $code, string $flow = 'sales'): bool
    {
        $code = strtolower((string) $code);

        return collect($this->allowedPaymentMethodCodes($flow))
            ->contains(fn (string $allowed) => $code === $allowed || str_starts_with($code, $allowed.'-'));
    }

    public function summary(): array
    {
        $itemMerge = app(DocumentItemMergePolicy::class);

        return [
            'quotationLabel' => $this->quotationLabel(),
            'saleNoteLabel' => $this->saleNoteLabel(),
            'ticketLabel' => $this->ticketLabel(),
            'documentMain' => ActiveBusinessProfile::payload()['sales']['document_main'] ?? 'sale_note',
            'defaultTerms' => $this->defaultTerms(),
            'termsByDocument' => ActiveBusinessProfile::payload()['sales']['terms_by_document'] ?? [],
            'visibleTemplateColumns' => $this->visibleTemplateColumns(),
            'allowedPaymentMethodCodes' => $this->allowedPaymentMethodCodes(),
            'paymentMethodsByFlow' => ActiveBusinessProfile::payload()['sales']['payment_methods_by_flow'] ?? [],
            'itemMerge' => $itemMerge->summary(),
        ];
    }

    private function salesValue(string $key, string $fallback): string
    {
        $value = ActiveBusinessProfile::payload()['sales'][$key] ?? null;

        return filled($value) ? (string) $value : $fallback;
    }
}
