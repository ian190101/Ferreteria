<?php

namespace App\Modules\Payments\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Purchases\Models\Purchase;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchasePayment extends AuditableModel
{
    use SoftDeletes;

    protected $fillable = [
        'purchase_id',
        'branch_id',
        'user_id',
        'payment_method_id',
        'paid_at',
        'amount',
        'reference',
        'notes',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'amount' => 'decimal:2',
    ];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }
}
