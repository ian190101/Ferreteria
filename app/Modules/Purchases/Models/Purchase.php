<?php

namespace App\Modules\Purchases\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Payments\Models\PurchasePayment;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Purchase extends AuditableModel
{
    use SoftDeletes;

    protected $fillable = [
        'branch_id',
        'supplier_id',
        'user_id',
        'document_number',
        'purchase_date',
        'total_amount',
        'paid_amount',
        'balance_due',
        'payment_status',
        'status',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'balance_due' => 'decimal:2',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchasePayment::class);
    }
}
