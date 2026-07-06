<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Customers\Models\Customer;
use App\Modules\Payments\Models\CreditNote;
use App\Modules\Payments\Models\PaymentPromise;
use App\Modules\Payments\Models\SalePayment;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sale extends AuditableModel
{
    use SoftDeletes;

    protected $fillable = [
        'branch_id',
        'user_id',
        'sale_type_id',
        'currency_id',
        'customer_id',
        'advance_option_id',
        'receipt_number',
        'document_type',
        'customer_name',
        'customer_document',
        'customer_contact',
        'sold_at',
        'exchange_rate_to_bob',
        'subtotal',
        'discount_total',
        'advance_percentage',
        'advance_amount',
        'balance_due',
        'total',
        'status',
        'terms',
        'internal_notes',
    ];

    protected $casts = [
        'sold_at' => 'datetime',
        'exchange_rate_to_bob' => 'decimal:6',
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'advance_percentage' => 'decimal:2',
        'advance_amount' => 'decimal:2',
        'balance_due' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function saleType(): BelongsTo
    {
        return $this->belongsTo(SaleType::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function advanceOption(): BelongsTo
    {
        return $this->belongsTo(AdvanceOption::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function deliveryNotes(): HasMany
    {
        return $this->hasMany(DeliveryNote::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function paymentPromises(): HasMany
    {
        return $this->hasMany(PaymentPromise::class);
    }
}
