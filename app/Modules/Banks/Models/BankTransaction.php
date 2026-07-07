<?php

namespace App\Modules\Banks\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankTransaction extends AuditableModel
{
    public const TYPE_DEPOSIT = 'deposit';

    public const TYPE_WITHDRAWAL = 'withdrawal';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const STATUS_REGISTERED = 'registered';

    public const STATUS_VOID = 'void';

    protected $fillable = [
        'bank_account_id',
        'branch_id',
        'user_id',
        'type',
        'transacted_at',
        'amount',
        'reference',
        'description',
        'status',
        'reconciled_at',
        'voided_at',
        'void_reason',
        'source_type',
        'source_id',
    ];

    protected $casts = [
        'transacted_at' => 'datetime',
        'amount' => 'decimal:2',
        'reconciled_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
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
