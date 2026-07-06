<?php

namespace App\Modules\Expenses\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends AuditableModel
{
    use SoftDeletes;

    public const STATUS_REGISTERED = 'registered';

    public const STATUS_VOID = 'void';

    protected $fillable = [
        'branch_id',
        'expense_category_id',
        'payment_method_id',
        'user_id',
        'spent_at',
        'description',
        'amount',
        'reference',
        'status',
        'notes',
    ];

    protected $casts = [
        'spent_at' => 'datetime',
        'amount' => 'decimal:2',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
