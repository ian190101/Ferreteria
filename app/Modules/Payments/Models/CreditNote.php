<?php

namespace App\Modules\Payments\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CreditNote extends AuditableModel
{
    use SoftDeletes;

    protected $fillable = [
        'sale_id',
        'branch_id',
        'user_id',
        'sale_return_id',
        'credit_number',
        'issued_at',
        'amount',
        'exchange_rate_to_bob',
        'amount_bob',
        'reason',
        'notes',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
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

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }
}
