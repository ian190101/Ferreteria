<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Branches\Models\Branch;
use App\Modules\Shared\Models\AuditableModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerQrOrder extends AuditableModel
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_PREPARING = 'preparing';

    public const STATUS_READY = 'ready';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CONVERTED = 'converted';

    protected $fillable = [
        'customer_qr_channel_id',
        'branch_id',
        'public_code',
        'status',
        'order_type',
        'customer_name',
        'customer_phone',
        'table_or_reference',
        'notes',
        'items',
        'subtotal',
        'service_fee',
        'total',
        'accepted_by_id',
        'rejected_by_id',
        'converted_by_id',
        'converted_to_type',
        'converted_to_id',
        'converted_at',
        'prepared_at',
        'ready_at',
        'delivered_at',
        'internal_notes',
        'client_ip_hash',
        'user_agent_hash',
        'submitted_at',
        'accepted_at',
        'rejected_at',
    ];

    protected $casts = [
        'items' => 'array',
        'subtotal' => 'decimal:2',
        'service_fee' => 'decimal:2',
        'total' => 'decimal:2',
        'submitted_at' => 'datetime',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'converted_at' => 'datetime',
        'prepared_at' => 'datetime',
        'ready_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(CustomerQrChannel::class, 'customer_qr_channel_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_id');
    }

    public function convertedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_by_id');
    }
}
