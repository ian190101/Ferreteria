<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryTransfer extends AuditableModel
{
    use SoftDeletes;

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'from_branch_id',
        'to_branch_id',
        'product_id',
        'product_coil_id',
        'user_id',
        'transfer_number',
        'tracking_mode',
        'meters',
        'status',
        'transferred_at',
        'reason',
        'notes',
    ];

    protected $casts = [
        'meters' => 'decimal:3',
        'transferred_at' => 'datetime',
    ];

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
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
