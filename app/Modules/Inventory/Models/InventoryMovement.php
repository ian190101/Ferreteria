<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InventoryMovement extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'branch_id',
        'product_id',
        'product_coil_id',
        'user_id',
        'source_type',
        'source_id',
        'type',
        'meters_delta',
        'meters_before',
        'meters_after',
        'reason',
        'created_at',
    ];

    protected $casts = [
        'meters_delta' => 'decimal:3',
        'meters_before' => 'decimal:3',
        'meters_after' => 'decimal:3',
        'created_at' => 'datetime',
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

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
