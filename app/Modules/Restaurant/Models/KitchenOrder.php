<?php

namespace App\Modules\Restaurant\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Sales\Models\Sale;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class KitchenOrder extends AuditableModel
{
    use SoftDeletes;

    public const STATUS_SENT = 'sent';
    public const STATUS_PREPARING = 'preparing';
    public const STATUS_READY = 'ready';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'branch_id',
        'restaurant_table_id',
        'sale_id',
        'waiter_user_id',
        'order_number',
        'customer_label',
        'channel',
        'preparation_area',
        'status',
        'subtotal',
        'sent_at',
        'prepared_at',
        'delivered_at',
        'closed_at',
        'notes',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'sent_at' => 'datetime',
        'prepared_at' => 'datetime',
        'delivered_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'restaurant_table_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function waiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waiter_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(KitchenOrderItem::class);
    }
}
