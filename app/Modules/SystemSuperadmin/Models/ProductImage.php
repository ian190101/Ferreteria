<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Inventory\Models\Product;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends AuditableModel
{
    protected $fillable = [
        'product_id',
        'url',
        'path',
        'alt_text',
        'is_primary',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
