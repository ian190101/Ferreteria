<?php

namespace App\Modules\Billing\Models;

use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiatProductMapping extends Model
{
    protected $fillable = [
        'product_id',
        'economic_activity_code',
        'sin_product_code',
        'unit_measure_code',
        'fiscal_description',
        'is_invoiceable',
    ];

    protected $casts = [
        'economic_activity_code' => 'integer',
        'sin_product_code' => 'integer',
        'unit_measure_code' => 'integer',
        'is_invoiceable' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
