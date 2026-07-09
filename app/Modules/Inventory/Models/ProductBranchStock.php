<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Branches\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBranchStock extends Model
{
    protected $fillable = [
        'branch_id',
        'product_id',
        'available_meters',
        'reserved_meters',
        'is_enabled',
    ];

    protected $casts = [
        'available_meters' => 'decimal:3',
        'reserved_meters' => 'decimal:3',
        'is_enabled' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
