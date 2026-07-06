<?php

namespace App\Modules\Cash\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashRegisterSession extends AuditableModel
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const CASH_DENOMINATIONS = [
        'bill_200' => 20000,
        'bill_100' => 10000,
        'bill_50' => 5000,
        'bill_20' => 2000,
        'bill_10' => 1000,
        'coin_5' => 500,
        'coin_2' => 200,
        'coin_1' => 100,
        'coin_050' => 50,
        'coin_020' => 20,
        'coin_010' => 10,
    ];

    protected $fillable = [
        'branch_id',
        'opened_by',
        'closed_by',
        'opened_at',
        'closed_at',
        'opening_amount',
        'cash_income_amount',
        'cash_expense_amount',
        'expected_cash_amount',
        'counted_cash_amount',
        'cash_count_breakdown',
        'difference_amount',
        'status',
        'opening_notes',
        'closing_notes',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'opening_amount' => 'decimal:2',
        'cash_income_amount' => 'decimal:2',
        'cash_expense_amount' => 'decimal:2',
        'expected_cash_amount' => 'decimal:2',
        'counted_cash_amount' => 'decimal:2',
        'cash_count_breakdown' => 'array',
        'difference_amount' => 'decimal:2',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
