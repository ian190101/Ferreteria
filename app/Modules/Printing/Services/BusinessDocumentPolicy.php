<?php

namespace App\Modules\Printing\Services;

use App\Modules\Printing\Models\PrintDocumentTemplate;
use App\Modules\SystemSuperadmin\Services\ActiveBusinessProfile;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;

class BusinessDocumentPolicy
{
    public const DOCUMENT_TYPES = [
        'quotation' => 'Cotizacion',
        'sale_note' => 'Nota de venta',
        'ticket_pos' => 'Ticket POS',
        'siat_invoice' => 'Factura SIAT',
        'credit_note' => 'Nota de credito',
        'kitchen_order' => 'Comanda / orden de cocina',
        'service_order' => 'Orden de servicio',
        'advance_receipt' => 'Recibo de anticipo',
        'reservation_receipt' => 'Recibo de reserva',
        'simple_contract' => 'Contrato simple',
        'delivery_receipt' => 'Comprobante de entrega',
        'barcode_label' => 'Etiqueta codigo de barras',
        'cash_closing' => 'Reporte de cierre de caja',
        'medical_record' => 'Ficha medica',
        'dental_chart' => 'Odontograma',
        'medical_prescription' => 'Receta medica',
        'consent_form' => 'Consentimiento',
        'loan_contract' => 'Contrato de prestamo',
        'rental_contract' => 'Contrato de alquiler',
        'guarantee_receipt' => 'Comprobante de garantia',
        'construction_budget' => 'Presupuesto de obra',
        'production_order' => 'Orden de produccion',
    ];

    public function allDocumentTypes(): array
    {
        return self::DOCUMENT_TYPES;
    }

    public function activeDocumentTypes(): array
    {
        $active = (array) data_get(
            ActiveBusinessProfile::payload(),
            'documents.active',
            BusinessProfileConfiguration::defaults()['documents']['active'] ?? [],
        );

        return collect($active)
            ->filter(fn ($type) => is_string($type) && array_key_exists($type, self::DOCUMENT_TYPES))
            ->unique()
            ->values()
            ->all();
    }

    public function printableDocumentTypes(): array
    {
        $printable = array_flip(PrintDocumentTemplate::DOCUMENT_TYPES);

        return collect($this->activeDocumentTypes())
            ->filter(fn (string $type) => isset($printable[$type]))
            ->mapWithKeys(fn (string $type) => [$type => self::DOCUMENT_TYPES[$type]])
            ->all();
    }

    public function isActive(string $documentType): bool
    {
        return in_array($documentType, $this->activeDocumentTypes(), true);
    }

    public function isPrintable(string $documentType): bool
    {
        return $this->isActive($documentType)
            && in_array($documentType, PrintDocumentTemplate::DOCUMENT_TYPES, true);
    }

    public function label(string $documentType): string
    {
        return self::DOCUMENT_TYPES[$documentType] ?? str_replace('_', ' ', $documentType);
    }

    public function summary(): array
    {
        return [
            'active' => $this->activeDocumentTypes(),
            'printable' => array_keys($this->printableDocumentTypes()),
            'labels' => collect($this->activeDocumentTypes())
                ->mapWithKeys(fn (string $type) => [$type => $this->label($type)])
                ->all(),
        ];
    }
}
