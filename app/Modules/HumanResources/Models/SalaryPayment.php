<?php

namespace App\Modules\HumanResources\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalaryPayment extends AuditableModel
{
    use SoftDeletes;

    public const STATUS_PAID = 'paid';
    public const STATUS_VOID = 'void';

    protected $fillable = [
        'worker_id',
        'branch_id',
        'payment_method_id',
        'expense_id',
        'user_id',
        'period_from',
        'period_to',
        'paid_at',
        'amount',
        'reference',
        'status',
        'notes',
    ];

    protected $casts = [
        'period_from' => 'date',
        'period_to' => 'date',
        'paid_at' => 'datetime',
        'amount' => 'decimal:2',
    ];

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
