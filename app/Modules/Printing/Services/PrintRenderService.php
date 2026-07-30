<?php

namespace App\Modules\Printing\Services;

use App\Modules\Printing\Models\PrintDocumentTemplate;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;

class PrintRenderService
{
    public function documentTypes(): array
    {
        return [
            'ticket_pos' => 'Ticket POS',
            'kitchen_order' => 'Comanda / orden de cocina',
            'service_order' => 'Orden de servicio',
            'advance_receipt' => 'Recibo de anticipo',
            'reservation_receipt' => 'Recibo de reserva',
            'simple_contract' => 'Contrato simple',
            'delivery_receipt' => 'Comprobante de entrega',
            'barcode_label' => 'Etiqueta codigo de barras',
            'cash_closing' => 'Reporte de cierre de caja',
        ];
    }

    public function areas(): array
    {
        return [
            'cashier' => 'Caja',
            'kitchen' => 'Cocina',
            'bar' => 'Barra',
            'warehouse' => 'Almacen',
            'labels' => 'Etiquetas',
            'billing' => 'Facturacion',
            'reports' => 'Reportes',
        ];
    }

    public function paperTypes(): array
    {
        return [
            'letter' => 'Carta',
            'half_letter' => 'Media carta vertical',
            'legal' => 'Oficio',
            'thermal_58' => 'Termica 58 mm',
            'thermal_80' => 'Termica 80 mm',
            'label_50x30' => 'Etiqueta 50 x 30 mm',
        ];
    }

    public function triggers(): array
    {
        return [
            'manual' => 'Manual',
            'sale_paid' => 'Al cobrar venta',
            'quote_created' => 'Al crear cotizacion',
            'kitchen_sent' => 'Al enviar a cocina',
            'service_created' => 'Al crear orden de servicio',
            'reservation_created' => 'Al crear reserva',
            'cash_closed' => 'Al cerrar caja',
            'barcode_requested' => 'Al pedir etiqueta',
        ];
    }

    public function render(PrintDocumentTemplate $template, array $payload = []): HtmlString
    {
        $payload = array_replace_recursive($this->defaultPayload($template->document_type), $payload);
        $layout = array_replace_recursive(
            PrintDocumentTemplate::defaultLayout($template->document_type),
            $template->layout ?? [],
        );
        $fields = array_values(array_filter($layout['fields'] ?? []));

        $html = '<section style="'.$this->pageStyle($template, $layout).'">';
        $html .= $this->logo($layout, $payload);
        $html .= '<header style="border-bottom:1px solid #111;padding-bottom:8px;margin-bottom:8px;">';
        $html .= '<strong style="font-size:1.15em;">'.e($payload['empresa'] ?? 'Empresa').'</strong>';
        $html .= '<div>'.e($this->documentTypes()[$template->document_type] ?? $template->document_type).'</div>';
        $html .= '<div>Nro.: '.e($payload['numero'] ?? '000001').'</div>';
        $html .= '</header>';

        foreach ($fields as $field) {
            if ($field === 'items') {
                $html .= $this->itemsTable($payload['items'] ?? []);
                continue;
            }

            if ($field === 'barcode' && (bool) ($layout['show_barcode'] ?? false)) {
                $html .= $this->barcode($payload['barcode'] ?? '');
                continue;
            }

            $value = Arr::get($payload, $field);
            if (is_array($value)) {
                $value = collect($value)->filter()->implode(' / ');
            }

            $html .= '<div style="display:flex;gap:6px;margin:3px 0;">';
            $html .= '<strong>'.e($this->fieldLabel($field)).':</strong>';
            $html .= '<span>'.e(filled($value) ? (string) $value : '-').'</span>';
            $html .= '</div>';
        }

        $html .= '<footer style="border-top:1px solid #111;margin-top:8px;padding-top:8px;font-size:.92em;">';
        $html .= e($payload['terminos'] ?? 'Documento generado por el sistema.');
        $html .= '</footer>';
        $html .= '</section>';

        return new HtmlString($html);
    }

    public function defaultPayload(string $documentType): array
    {
        $baseItems = [
            ['descripcion' => 'Producto de ejemplo', 'cantidad' => '2', 'unidad' => 'u', 'precio' => '25,0', 'subtotal' => '50,0'],
            ['descripcion' => 'Servicio o adicional', 'cantidad' => '1', 'unidad' => 'serv', 'precio' => '80,0', 'subtotal' => '80,0'],
        ];

        return match ($documentType) {
            'kitchen_order' => [
                'empresa' => 'Cocina',
                'numero' => 'CMD-000001',
                'mesa' => 'Mesa 4',
                'mesero' => 'Mesero demo',
                'items' => [
                    ['descripcion' => 'Milanesa completa', 'cantidad' => '1', 'unidad' => 'plato', 'precio' => '-', 'subtotal' => '-'],
                    ['descripcion' => 'Limonada', 'cantidad' => '2', 'unidad' => 'vaso', 'precio' => '-', 'subtotal' => '-'],
                ],
                'notas' => 'Sin cebolla. Preparar bebida en barra.',
                'hora' => now()->format('H:i'),
                'terminos' => 'Orden interna para preparacion.',
            ],
            'service_order' => [
                'empresa' => 'Servicio tecnico',
                'numero' => 'OS-000001',
                'cliente' => 'Cliente demo',
                'tecnico' => 'Tecnico asignado',
                'diagnostico' => 'Revision inicial pendiente.',
                'materiales' => 'Materiales opcionales segun avance.',
                'garantia' => 'Garantia segun condiciones acordadas.',
            ],
            'reservation_receipt' => [
                'empresa' => 'Reservas',
                'numero' => 'RES-000001',
                'cliente' => 'Cliente demo',
                'recurso' => 'Mesa / tecnico / equipo',
                'inicio' => now()->format('d/m/Y H:i'),
                'fin' => now()->addHour()->format('d/m/Y H:i'),
                'anticipo' => 'Bs 0,0',
                'garantia' => 'Bs 0,0',
            ],
            'simple_contract' => [
                'empresa' => 'Contrato',
                'numero' => 'CON-000001',
                'cliente' => 'Cliente demo',
                'condiciones' => 'Condiciones comerciales configurables por plantilla.',
                'firma_cliente' => 'Firma cliente',
                'firma_empresa' => 'Firma empresa',
            ],
            'barcode_label' => [
                'empresa' => 'Etiqueta',
                'numero' => 'LBL-000001',
                'producto' => 'Producto demo',
                'sku' => 'SKU-DEMO',
                'precio' => 'Bs 25,0',
                'barcode' => '7750000000012',
                'terminos' => 'Codigo interno del producto.',
            ],
            'cash_closing' => [
                'empresa' => 'Caja',
                'numero' => 'CIERRE-000001',
                'sucursal' => 'Sucursal Central',
                'usuario' => 'Cajero demo',
                'efectivo' => 'Bs 1.200,0',
                'qr_banco' => 'Bs 350,0',
                'diferencia' => 'Bs 0,0',
            ],
            default => [
                'empresa' => 'Empresa demo',
                'numero' => 'DOC-000001',
                'cliente' => 'Cliente ocasional',
                'items' => $baseItems,
                'total' => 'Bs 130,0',
                'metodo_pago' => 'Efectivo',
                'terminos' => 'Gracias por su preferencia.',
            ],
        };
    }

    private function pageStyle(PrintDocumentTemplate $template, array $layout): string
    {
        $width = match ($template->paper_type) {
            'thermal_58' => '58mm',
            'thermal_80' => '80mm',
            'label_50x30' => '50mm',
            'half_letter' => '139.7mm',
            default => '100%',
        };

        return collect([
            'box-sizing:border-box',
            'width:'.$width,
            'max-width:100%',
            'min-height:'.($template->paper_type === 'label_50x30' ? '30mm' : 'auto'),
            'background:#fff',
            'color:'.e($layout['color'] ?? '#000'),
            'font-family:'.e($layout['font_family'] ?? 'monospace'),
            'font-size:'.((int) ($layout['font_size'] ?? 12)).'px',
            'line-height:1.25',
            'padding:'.((int) ($layout['margin_mm'] ?? 4)).'mm',
        ])->implode(';');
    }

    private function logo(array $layout, array $payload): string
    {
        if (! ($layout['show_logo'] ?? false) || blank($payload['logo'] ?? null)) {
            return '';
        }

        return '<img src="'.e($payload['logo']).'" alt="" style="max-width:80px;max-height:48px;object-fit:contain;margin-bottom:6px;">';
    }

    private function itemsTable(array $items): string
    {
        if ($items === []) {
            return '<p>Sin items.</p>';
        }

        $html = '<table style="width:100%;border-collapse:collapse;margin:8px 0;">';
        $html .= '<thead><tr>';
        foreach (['Descripcion', 'Cant.', 'Und.', 'Precio', 'Subtotal'] as $heading) {
            $html .= '<th style="border-bottom:1px solid #111;text-align:left;padding:2px;">'.e($heading).'</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($items as $item) {
            $html .= '<tr>';
            foreach (['descripcion', 'cantidad', 'unidad', 'precio', 'subtotal'] as $key) {
                $html .= '<td style="padding:2px;border-bottom:1px solid #ddd;">'.e((string) ($item[$key] ?? '-')).'</td>';
            }
            $html .= '</tr>';
        }

        return $html.'</tbody></table>';
    }

    private function barcode(string $value): string
    {
        if (blank($value)) {
            return '';
        }

        $bars = collect(str_split($value))
            ->map(fn (string $char, int $index) => '<span style="display:inline-block;width:'.((ord($char) + $index) % 3 + 1).'px;height:34px;background:#111;margin-right:1px;"></span>')
            ->implode('');

        return '<div style="margin:6px 0;text-align:center;"><div style="display:inline-flex;align-items:flex-end;">'.$bars.'</div><div>'.e($value).'</div></div>';
    }

    private function fieldLabel(string $field): string
    {
        return [
            'numero' => 'Numero',
            'cliente' => 'Cliente',
            'tecnico' => 'Tecnico',
            'diagnostico' => 'Diagnostico',
            'materiales' => 'Materiales',
            'garantia' => 'Garantia',
            'metodo_pago' => 'Metodo de pago',
            'qr_banco' => 'QR/Banco',
            'firma_cliente' => 'Firma cliente',
            'firma_empresa' => 'Firma empresa',
        ][$field] ?? ucfirst(str_replace('_', ' ', $field));
    }
}
