<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Branches\Models\Branch;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BarcodeLabelTemplate extends AuditableModel
{
    protected $fillable = [
        'branch_id',
        'name',
        'paper_type',
        'label_width_mm',
        'label_height_mm',
        'margin_mm',
        'barcode_height_mm',
        'font_size',
        'show_product_name',
        'show_sku',
        'show_price',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'show_product_name' => 'boolean',
        'show_sku' => 'boolean',
        'show_price' => 'boolean',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
