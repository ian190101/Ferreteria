<?php

namespace App\Modules\Sales\Models;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCoil;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleItem extends Model
{
    protected $fillable = [
        'sale_id',
        'product_id',
        'product_coil_id',
        'description',
        'unit_label',
        'display_quantity',
        'display_unit_label',
        'item_attributes',
        'calculation_mode',
        'meters',
        'unit_price',
        'discount_amount',
        'total',
    ];

    protected $casts = [
        'meters' => 'decimal:3',
        'display_quantity' => 'decimal:3',
        'item_attributes' => 'array',
        'unit_price' => 'decimal:4',
        'discount_amount' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function coil(): BelongsTo
    {
        return $this->belongsTo(ProductCoil::class, 'product_coil_id');
    }

    public function deliveryItems(): HasMany
    {
        return $this->hasMany(DeliveryNoteItem::class);
    }

    public function returnItems(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }
}
