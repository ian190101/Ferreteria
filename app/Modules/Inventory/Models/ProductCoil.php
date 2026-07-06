<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Branches\Models\Branch;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductCoil extends AuditableModel
{
    use SoftDeletes;

    protected $fillable = [
        'branch_id',
        'product_id',
        'barcode',
        'lot_number',
        'initial_meters',
        'available_meters',
        'initial_kg',
        'status',
    ];

    protected $casts = [
        'initial_meters' => 'decimal:3',
        'available_meters' => 'decimal:3',
        'initial_kg' => 'decimal:3',
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
