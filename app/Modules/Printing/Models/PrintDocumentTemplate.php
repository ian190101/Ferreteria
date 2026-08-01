<?php

namespace App\Modules\Printing\Models;

use App\Modules\Branches\Models\Branch;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PrintDocumentTemplate extends AuditableModel
{
    use SoftDeletes;

    public const DOCUMENT_TYPES = [
        'ticket_pos',
        'siat_invoice',
        'credit_note',
        'kitchen_order',
        'service_order',
        'advance_receipt',
        'reservation_receipt',
        'simple_contract',
        'delivery_receipt',
        'barcode_label',
        'cash_closing',
        'medical_record',
        'dental_chart',
        'medical_prescription',
        'consent_form',
        'loan_contract',
        'rental_contract',
        'guarantee_receipt',
        'construction_budget',
        'production_order',
    ];

    protected $fillable = [
        'branch_id',
        'document_type',
        'name',
        'paper_type',
        'thermal_width_mm',
        'layout',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'thermal_width_mm' => 'integer',
        'layout' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public static function defaultLayout(string $documentType): array
    {
        return [
            'font_family' => 'monospace',
            'font_size' => in_array($documentType, ['ticket_pos', 'kitchen_order', 'barcode_label'], true) ? 10 : 12,
            'margin_mm' => 4,
            'show_logo' => ! in_array($documentType, ['kitchen_order', 'barcode_label'], true),
            'show_barcode' => $documentType === 'barcode_label',
            'color' => '#000000',
            'fields' => self::defaultFields($documentType),
        ];
    }

    private static function defaultFields(string $documentType): array
    {
        return match ($documentType) {
            'siat_invoice' => ['numero', 'cliente', 'nit', 'cuf', 'items', 'total', 'leyenda'],
            'credit_note' => ['numero', 'cliente', 'documento_original', 'motivo', 'items', 'total'],
            'kitchen_order' => ['numero', 'mesa', 'items', 'notas', 'hora'],
            'service_order' => ['numero', 'cliente', 'tecnico', 'diagnostico', 'materiales', 'garantia'],
            'reservation_receipt' => ['numero', 'cliente', 'recurso', 'inicio', 'fin', 'anticipo', 'garantia'],
            'simple_contract' => ['cliente', 'condiciones', 'firma_cliente', 'firma_empresa'],
            'barcode_label' => ['producto', 'sku', 'precio', 'barcode'],
            'cash_closing' => ['sucursal', 'usuario', 'efectivo', 'qr_banco', 'diferencia'],
            'medical_record' => ['numero', 'paciente', 'motivo_consulta', 'diagnostico', 'tratamiento', 'proxima_cita'],
            'dental_chart' => ['numero', 'paciente', 'tipo_paciente', 'piezas', 'tratamientos', 'observaciones'],
            'medical_prescription' => ['numero', 'paciente', 'diagnostico', 'indicaciones', 'profesional'],
            'consent_form' => ['paciente', 'procedimiento', 'riesgos', 'condiciones', 'firma_cliente', 'firma_empresa'],
            'loan_contract' => ['numero', 'cliente', 'monto', 'interes', 'garantia', 'cuotas', 'firma_cliente', 'firma_empresa'],
            'rental_contract' => ['numero', 'cliente', 'recurso', 'inicio', 'fin', 'garantia', 'penalidad'],
            'guarantee_receipt' => ['numero', 'cliente', 'garantia', 'valor_garantia', 'estado'],
            'construction_budget' => ['numero', 'cliente', 'proyecto', 'materiales', 'mano_obra', 'total'],
            'production_order' => ['numero', 'producto', 'insumos', 'merma', 'mano_obra', 'costo'],
            default => ['empresa', 'numero', 'cliente', 'items', 'total', 'metodo_pago'],
        };
    }
}
