<?php

namespace App\Modules\Purchases\Models;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCoil;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseItem extends Model
{
    protected $fillable = [
        'purchase_id',
        'product_id',
        'product_coil_id',
        'coil_barcode',
        'display_quantity',
        'display_unit_label',
        'item_attributes',
        'calculation_mode',
        'kilograms',
        'meters',
        'unit_cost',
        'conversion_factor',
        'lot_number',
        'description',
    ];

    protected $casts = [
        'kilograms' => 'decimal:3',
        'meters' => 'decimal:3',
        'display_quantity' => 'decimal:3',
        'item_attributes' => 'array',
        'unit_cost' => 'decimal:4',
        'conversion_factor' => 'decimal:6',
    ];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function coil(): BelongsTo
    {
        return $this->belongsTo(ProductCoil::class, 'product_coil_id');
    }
}
