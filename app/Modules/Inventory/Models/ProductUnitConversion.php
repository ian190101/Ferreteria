<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductUnitConversion extends AuditableModel
{
    protected $fillable = [
        'product_id',
        'product_unit_id',
        'factor_to_base',
        'is_active',
    ];

    protected $casts = [
        'factor_to_base' => 'decimal:6',
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }
}
