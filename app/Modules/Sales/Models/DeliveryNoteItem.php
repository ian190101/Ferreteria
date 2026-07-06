<?php

namespace App\Modules\Sales\Models;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCoil;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryNoteItem extends Model
{
    protected $fillable = [
        'delivery_note_id',
        'sale_item_id',
        'product_id',
        'product_coil_id',
        'meters',
    ];

    protected $casts = [
        'meters' => 'decimal:3',
    ];

    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
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
