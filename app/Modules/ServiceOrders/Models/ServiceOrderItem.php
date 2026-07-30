<?php

namespace App\Modules\ServiceOrders\Models;

use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceOrderItem extends Model
{
    protected $fillable = [
        'service_order_id',
        'product_id',
        'quantity',
        'unit_label',
        'unit_cost',
        'unit_price',
        'subtotal',
        'discount_inventory',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'subtotal' => 'decimal:4',
        'discount_inventory' => 'boolean',
    ];

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
