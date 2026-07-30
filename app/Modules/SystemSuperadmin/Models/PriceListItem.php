<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Inventory\Models\Product;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceListItem extends AuditableModel
{
    protected $fillable = [
        'price_list_id',
        'product_id',
        'product_variant_id',
        'price',
        'min_quantity',
        'conditions',
    ];

    protected $casts = [
        'price' => 'decimal:4',
        'min_quantity' => 'decimal:4',
        'conditions' => 'array',
    ];

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
