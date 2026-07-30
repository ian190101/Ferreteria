<?php

namespace App\Modules\Restaurant\Models;

use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KitchenOrderItem extends Model
{
    protected $fillable = [
        'kitchen_order_id',
        'product_id',
        'recipe_id',
        'quantity',
        'unit_label',
        'unit_price',
        'total',
        'status',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:4',
        'total' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(KitchenOrder::class, 'kitchen_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
