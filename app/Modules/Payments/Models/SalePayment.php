<?php

namespace App\Modules\Payments\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Cash\Models\CashRegisterSession;
use App\Modules\Sales\Models\Sale;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Builder;
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
        'cash_register_session_id',
        'cash_context_recorded',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'amount' => 'decimal:2',
        'exchange_rate_to_bob' => 'decimal:6',
        'amount_bob' => 'decimal:2',
        'cash_context_recorded' => 'boolean',
    ];

    public function scopeWithCashContext(Builder $query): Builder
    {
        // Solo los cobros anteriores al registro explícito se reconstruyen por turno, usuario y sucursal.
        $historicalSession = CashRegisterSession::query()
            ->select('cash_register_sessions.id')
            ->whereColumn('cash_register_sessions.branch_id', 'sale_payments.branch_id')
            ->whereColumn('cash_register_sessions.opened_by', 'sale_payments.user_id')
            ->whereColumn('cash_register_sessions.opened_at', '<=', 'sale_payments.paid_at')
            ->where(fn (Builder $session) => $session
                ->whereNull('cash_register_sessions.closed_at')
                ->orWhereColumn('cash_register_sessions.closed_at', '>=', 'sale_payments.paid_at'))
            ->orderByDesc('cash_register_sessions.id')
            ->limit(1);

        return $query->addSelect('sale_payments.*')->selectRaw(
            'CASE WHEN sale_payments.cash_context_recorded = ? THEN sale_payments.cash_register_session_id ELSE ('.$historicalSession->toSql().') END AS collection_cash_session_id',
            [true, ...$historicalSession->getBindings()],
        );
    }

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
