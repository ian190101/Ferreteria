<?php

namespace App\Modules\Reservations\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Customers\Models\Customer;
use App\Modules\HumanResources\Models\Worker;
use App\Modules\Shared\Models\AuditableModel;
use App\Modules\SystemSuperadmin\Models\ReservableResource;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BusinessReservation extends AuditableModel
{
    use SoftDeletes;

    public const TYPE_RESERVATION = 'reservation';
    public const TYPE_RENTAL = 'rental';

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_NO_SHOW = 'no_show';
    public const STATUS_RESCHEDULED = 'rescheduled';

    protected $fillable = [
        'branch_id',
        'customer_id',
        'reservable_resource_id',
        'worker_id',
        'user_id',
        'reservation_number',
        'type',
        'channel',
        'status',
        'customer_name',
        'customer_phone',
        'title',
        'start_at',
        'end_at',
        'checked_out_at',
        'returned_at',
        'amount',
        'advance_amount',
        'deposit_amount',
        'penalty_amount',
        'total_amount',
        'condition_before',
        'condition_after',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'checked_out_at' => 'datetime',
        'returned_at' => 'datetime',
        'amount' => 'decimal:4',
        'advance_amount' => 'decimal:4',
        'deposit_amount' => 'decimal:4',
        'penalty_amount' => 'decimal:4',
        'total_amount' => 'decimal:4',
        'metadata' => 'array',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(ReservableResource::class, 'reservable_resource_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BusinessReservationItem::class);
    }
}
