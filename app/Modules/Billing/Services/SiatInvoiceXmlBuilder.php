<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\SiatInvoice;
use DOMDocument;

class SiatInvoiceXmlBuilder
{
    public function buildCompraVenta(SiatInvoice $invoice): string
    {
        $invoice->loadMissing('items');
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;

        $root = $document->createElement('facturaComputarizadaCompraVenta');
        $root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $root->setAttribute('xsi:noNamespaceSchemaLocation', 'facturaComputarizadaCompraVenta.xsd');
        $document->appendChild($root);

        $header = $document->createElement('cabecera');
        $root->appendChild($header);

        $this->append($document, $header, 'nitEmisor', $invoice->branch?->siatSetting?->nit ?? '');
        $this->append($document, $header, 'razonSocialEmisor', $invoice->branch?->siatSetting?->business_name ?? '');
        $this->append($document, $header, 'municipio', $invoice->branch?->siatSetting?->municipality ?? '');
        $this->appendNullable($document, $header, 'telefono', $invoice->branch?->siatSetting?->phone);
        $this->append($document, $header, 'numeroFactura', $invoice->invoice_number);
        $this->append($document, $header, 'cuf', $invoice->cuf);
        $this->append($document, $header, 'cufd', $invoice->cufd);
        $this->append($document, $header, 'codigoSucursal', $invoice->siat_branch_code);
        $this->append($document, $header, 'direccion', $invoice->cufd?->address ?? $invoice->branch?->address ?? 'Sin direccion');
        $this->appendNullable($document, $header, 'codigoPuntoVenta', $invoice->point_of_sale_code > 0 ? $invoice->point_of_sale_code : null);
        $this->append($document, $header, 'fechaEmision', $invoice->issued_at?->format('Y-m-d\TH:i:s.v') ?? now()->format('Y-m-d\TH:i:s.v'));
        $this->appendNullable($document, $header, 'nombreRazonSocial', $invoice->customer_name);
        $this->append($document, $header, 'codigoTipoDocumentoIdentidad', $invoice->identity_document_type_code);
        $this->append($document, $header, 'numeroDocumento', $invoice->customer_document);
        $this->appendNullable($document, $header, 'complemento', $invoice->customer_complement);
        $this->append($document, $header, 'codigoCliente', $invoice->customer_code);
        $this->append($document, $header, 'codigoMetodoPago', $invoice->payment_method_code);
        $this->appendNullable($document, $header, 'numeroTarjeta', $invoice->card_number_masked);
        $this->append($document, $header, 'montoTotal', $this->amount($invoice->total_amount));
        $this->append($document, $header, 'montoTotalSujetoIva', $this->amount($invoice->taxable_amount));
        $this->appendNullable($document, $header, 'montoGiftCard', $invoice->gift_card_amount);
        $this->appendNullable($document, $header, 'descuentoAdicional', $invoice->additional_discount);
        $this->append($document, $header, 'codigoExcepcion', $invoice->exception_code);
        $this->appendNullable($document, $header, 'cafc', $invoice->cafc);
        $this->append($document, $header, 'codigoMoneda', $invoice->currency_code);
        $this->append($document, $header, 'tipoCambio', $this->amount($invoice->exchange_rate, 6));
        $this->append($document, $header, 'montoTotalMoneda', $this->amount($invoice->total_amount_currency));
        $this->append($document, $header, 'leyenda', $invoice->legend ?: 'Ley N 453: El proveedor debe brindar atencion sin discriminacion.');
        $this->append($document, $header, 'usuario', $invoice->operator_username);
        $this->append($document, $header, 'codigoDocumentoSector', $invoice->document_sector_code);

        foreach ($invoice->items as $item) {
            $detail = $document->createElement('detalle');
            $root->appendChild($detail);

            $this->append($document, $detail, 'actividadEconomica', $item->economic_activity_code);
            $this->append($document, $detail, 'codigoProductoSin', $item->sin_product_code);
            $this->append($document, $detail, 'codigoProducto', $item->product_code);
            $this->append($document, $detail, 'descripcion', $item->description);
            $this->append($document, $detail, 'cantidad', $this->amount($item->quantity, 4));
            $this->append($document, $detail, 'unidadMedida', $item->unit_measure_code);
            $this->append($document, $detail, 'precioUnitario', $this->amount($item->unit_price, 4));
            $this->appendNullable($document, $detail, 'montoDescuento', $item->discount_amount);
            $this->append($document, $detail, 'subTotal', $this->amount($item->subtotal));
            $this->appendNullable($document, $detail, 'numeroSerie', $item->serial_number);
            $this->appendNullable($document, $detail, 'numeroImei', $item->imei_number);
        }

        return (string) $document->saveXML();
    }

    private function append(DOMDocument $document, \DOMElement $parent, string $name, mixed $value): void
    {
        $element = $document->createElement($name);
        $element->appendChild($document->createTextNode((string) $value));

        $parent->appendChild($element);
    }

    private function appendNullable(DOMDocument $document, \DOMElement $parent, string $name, mixed $value): void
    {
        $element = $document->createElement($name);

        if ($value === null || $value === '') {
            $element->setAttribute('xsi:nil', 'true');
        } else {
            $element->appendChild($document->createTextNode((string) $value));
        }

        $parent->appendChild($element);
    }

    private function amount(mixed $value, int $decimals = 2): string
    {
        return number_format((float) $value, $decimals, '.', '');
    }
}
