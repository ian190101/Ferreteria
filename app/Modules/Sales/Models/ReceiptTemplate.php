<?php

namespace App\Modules\Sales\Models;

use App\Modules\Branches\Models\Branch;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReceiptTemplate extends AuditableModel
{
    use SoftDeletes;

    public const PAPER_TYPES = ['letter', 'half_letter', 'legal', 'half_legal', 'full_page', 'thermal'];

    public const DOCUMENT_TYPES = ['both', 'quotation', 'sale_note'];

    protected $fillable = [
        'branch_id',
        'name',
        'document_type',
        'paper_type',
        'thermal_width_mm',
        'use_branding',
        'layout',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'layout' => 'array',
        'thermal_width_mm' => 'integer',
        'use_branding' => 'boolean',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public static function defaultLayout(): array
    {
        return [
            'font_family' => 'monospace',
            'font_size' => 13,
            'margin_mm' => 8,
            'logo' => [
                'path' => null,
                'width_mm' => 28,
                'position' => 'left',
                'show' => false,
            ],
            'colors' => [
                'primary' => '#000000',
                'secondary' => '#000000',
            ],
            'sections' => [
                ['key' => 'header', 'label' => 'Encabezado', 'show' => true, 'order' => 1, 'align' => 'center'],
                ['key' => 'document', 'label' => 'Datos documento', 'show' => true, 'order' => 2, 'align' => 'right'],
                ['key' => 'customer', 'label' => 'Cliente', 'show' => true, 'order' => 3, 'columns' => 2],
                ['key' => 'items', 'label' => 'Items', 'show' => true, 'order' => 4],
                ['key' => 'totals', 'label' => 'Totales', 'show' => true, 'order' => 5],
                ['key' => 'terms', 'label' => 'Notas', 'show' => true, 'order' => 6],
            ],
            'fields' => [
                'branch_name' => true,
                'branch_address' => true,
                'branch_phone' => true,
                'branch_secondary_phone' => true,
                'document_title' => true,
                'receipt_number' => true,
                'date' => true,
                'currency' => true,
                'seller' => true,
                'point_of_sale' => true,
                'customer' => true,
                'sale_type' => true,
                'customer_contact' => true,
                'exchange_rate' => true,
                'item_number' => true,
                'item_description' => true,
                'item_lot' => false,
                'item_model' => true,
                'item_unit' => true,
                'item_quantity' => true,
                'item_base' => true,
                'item_price' => true,
                'item_subtotal' => true,
                'subtotal' => true,
                'discount' => true,
                'advance' => true,
                'balance_due' => true,
            ],
            'item_columns' => [
                ['key' => 'item_number', 'label' => 'N', 'show' => true, 'order' => 1],
                ['key' => 'item_description', 'label' => 'Descripcion', 'show' => true, 'order' => 2],
                ['key' => 'item_lot', 'label' => 'Lote', 'show' => false, 'order' => 3],
                ['key' => 'item_model', 'label' => 'Modelo', 'show' => true, 'order' => 4],
                ['key' => 'item_unit', 'label' => 'Und.', 'show' => true, 'order' => 50],
                ['key' => 'item_quantity', 'label' => 'Cant.', 'show' => true, 'order' => 60],
                ['key' => 'item_base', 'label' => 'Base', 'show' => true, 'order' => 70],
                ['key' => 'item_price', 'label' => 'Precio', 'show' => true, 'order' => 80],
                ['key' => 'item_subtotal', 'label' => 'Subtotal', 'show' => true, 'order' => 90],
            ],
        ];
    }
}
