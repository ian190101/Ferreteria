<?php

namespace App\Modules\Payments\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Sales\Models\Sale;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentPromise extends AuditableModel
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_BROKEN = 'broken';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'sale_id',
        'branch_id',
        'user_id',
        'promise_number',
        'promised_date',
        'promised_amount',
        'contact_name',
        'contact_phone',
        'channel',
        'status',
        'notes',
        'resolved_at',
    ];

    protected $casts = [
        'promised_date' => 'date',
        'promised_amount' => 'decimal:2',
        'resolved_at' => 'datetime',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
