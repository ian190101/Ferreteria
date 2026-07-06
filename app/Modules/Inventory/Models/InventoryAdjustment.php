<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryAdjustment extends AuditableModel
{
    use SoftDeletes;

    public const TYPE_INCREASE = 'increase';

    public const TYPE_DECREASE = 'decrease';

    protected $fillable = [
        'branch_id',
        'product_id',
        'product_coil_id',
        'user_id',
        'adjustment_number',
        'type',
        'meters_delta',
        'meters_before',
        'meters_after',
        'reason',
        'adjusted_at',
        'notes',
    ];

    protected $casts = [
        'meters_delta' => 'decimal:3',
        'meters_before' => 'decimal:3',
        'meters_after' => 'decimal:3',
        'adjusted_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function coil(): BelongsTo
    {
        return $this->belongsTo(ProductCoil::class, 'product_coil_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
