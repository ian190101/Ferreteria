<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Inventory\Models\Product;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends AuditableModel
{
    protected $fillable = [
        'product_id',
        'sku',
        'barcode',
        'name',
        'attributes',
        'cost_price',
        'sale_price',
        'is_active',
    ];

    protected $casts = [
        'attributes' => 'array',
        'cost_price' => 'decimal:4',
        'sale_price' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
