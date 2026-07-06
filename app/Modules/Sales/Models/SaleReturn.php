<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Payments\Models\CreditNote;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SaleReturn extends AuditableModel
{
    use SoftDeletes;

    protected $fillable = [
        'sale_id',
        'branch_id',
        'user_id',
        'return_number',
        'returned_at',
        'total_amount',
        'reason',
        'notes',
    ];

    protected $casts = [
        'returned_at' => 'datetime',
        'total_amount' => 'decimal:2',
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

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }
}
