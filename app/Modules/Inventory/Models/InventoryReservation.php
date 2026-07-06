<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Sales\Models\Sale;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryReservation extends AuditableModel
{
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RELEASED = 'released';

    public const STATUS_CONSUMED = 'consumed';

    protected $fillable = [
        'branch_id',
        'product_id',
        'product_coil_id',
        'sale_id',
        'consumed_sale_id',
        'user_id',
        'meters',
        'status',
        'expires_at',
        'released_at',
        'consumed_at',
        'reason',
        'notes',
    ];

    protected $casts = [
        'meters' => 'decimal:3',
        'expires_at' => 'datetime',
        'released_at' => 'datetime',
        'consumed_at' => 'datetime',
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

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function consumedSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'consumed_sale_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
