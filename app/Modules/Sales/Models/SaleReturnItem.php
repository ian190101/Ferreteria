<?php

namespace App\Modules\Sales\Models;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCoil;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleReturnItem extends Model
{
    protected $fillable = [
        'sale_return_id',
        'sale_item_id',
        'product_id',
        'product_coil_id',
        'meters',
        'unit_price',
        'discount_amount',
        'total',
    ];

    protected $casts = [
        'meters' => 'decimal:3',
        'unit_price' => 'decimal:4',
        'discount_amount' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
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
