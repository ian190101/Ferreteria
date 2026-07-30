<?php

namespace App\Modules\ServiceOrders\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Customers\Models\Customer;
use App\Modules\HumanResources\Models\Worker;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceOrder extends AuditableModel
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_FINISHED = 'finished';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'branch_id',
        'customer_id',
        'worker_id',
        'user_id',
        'order_number',
        'customer_name',
        'customer_phone',
        'title',
        'service_type',
        'status',
        'scheduled_at',
        'started_at',
        'finished_at',
        'delivered_at',
        'labor_amount',
        'materials_amount',
        'advance_amount',
        'total_amount',
        'diagnosis',
        'work_performed',
        'warranty_terms',
        'evidence',
        'signature',
        'notes',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'delivered_at' => 'datetime',
        'labor_amount' => 'decimal:4',
        'materials_amount' => 'decimal:4',
        'advance_amount' => 'decimal:4',
        'total_amount' => 'decimal:4',
        'evidence' => 'array',
        'signature' => 'array',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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
        return $this->hasMany(ServiceOrderItem::class);
    }
}
