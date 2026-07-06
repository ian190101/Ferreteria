<?php

namespace App\Modules\Purchases\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends AuditableModel
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PARTIAL_RECEIVED = 'partial_received';

    public const STATUS_CONVERTED = 'converted';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'branch_id',
        'supplier_id',
        'user_id',
        'approved_by',
        'converted_purchase_id',
        'order_number',
        'ordered_at',
        'expected_at',
        'total_amount',
        'status',
        'notes',
        'approved_at',
        'converted_at',
    ];

    protected $casts = [
        'ordered_at' => 'date',
        'expected_at' => 'date',
        'total_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'converted_at' => 'datetime',
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

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function convertedPurchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'converted_purchase_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseOrderReceipt::class);
    }
}
