<?php

namespace App\Modules\Payments\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Sales\Models\Sale;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalePayment extends AuditableModel
{
    use SoftDeletes;

    protected $fillable = [
        'sale_id',
        'branch_id',
        'user_id',
        'payment_method_id',
        'paid_at',
        'amount',
        'exchange_rate_to_bob',
        'amount_bob',
        'reference',
        'notes',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'amount' => 'decimal:2',
        'exchange_rate_to_bob' => 'decimal:6',
        'amount_bob' => 'decimal:2',
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

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }
}
