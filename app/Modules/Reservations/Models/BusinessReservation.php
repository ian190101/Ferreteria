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

    public const CONFIRMATION_PENDING = 'pending';
    public const CONFIRMATION_CONFIRMED = 'confirmed';
    public const CONFIRMATION_REJECTED = 'rejected';

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
        'rescheduled_from_id',
        'reservation_number',
        'type',
        'appointment_kind',
        'channel',
        'status',
        'confirmation_status',
        'customer_name',
        'customer_phone',
        'title',
        'start_at',
        'end_at',
        'confirmed_at',
        'reminder_at',
        'cancelled_at',
        'no_show_at',
        'rescheduled_at',
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
        'confirmed_at' => 'datetime',
        'reminder_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'no_show_at' => 'datetime',
        'rescheduled_at' => 'datetime',
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

    public function rescheduledFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rescheduled_from_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BusinessReservationItem::class);
    }
}
