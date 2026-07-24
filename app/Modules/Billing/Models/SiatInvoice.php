<?php

namespace App\Modules\Billing\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Sales\Models\Sale;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SiatInvoice extends AuditableModel
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_OBSERVED = 'observed';
    public const STATUS_CONTINGENCY = 'contingency';
    public const STATUS_TEMPORARY = 'temporary';
    public const STATUS_VOIDED = 'voided';

    protected $fillable = [
        'sale_id',
        'branch_id',
        'user_id',
        'siat_cufd_id',
        'invoice_number',
        'cuf',
        'cufd',
        'environment_code',
        'modality_code',
        'emission_type_code',
        'invoice_type_code',
        'document_sector_code',
        'siat_branch_code',
        'point_of_sale_code',
        'issued_at',
        'customer_name',
        'identity_document_type_code',
        'customer_document',
        'customer_complement',
        'customer_code',
        'payment_method_code',
        'card_number_masked',
        'total_amount',
        'taxable_amount',
        'gift_card_amount',
        'additional_discount',
        'exception_code',
        'cafc',
        'currency_code',
        'exchange_rate',
        'total_amount_currency',
        'legend',
        'operator_username',
        'xml',
        'signed_xml',
        'xml_hash',
        'gzip_hash',
        'gzip_base64',
        'reception_code',
        'siat_state_code',
        'status',
        'siat_response',
        'observations',
        'sent_at',
        'validated_at',
        'voided_at',
        'void_reason_code',
        'void_reason',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'total_amount' => 'decimal:2',
        'taxable_amount' => 'decimal:2',
        'gift_card_amount' => 'decimal:2',
        'additional_discount' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'total_amount_currency' => 'decimal:2',
        'siat_response' => 'array',
        'observations' => 'array',
        'sent_at' => 'datetime',
        'validated_at' => 'datetime',
        'voided_at' => 'datetime',
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

    public function cufd(): BelongsTo
    {
        return $this->belongsTo(SiatCufd::class, 'siat_cufd_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SiatInvoiceItem::class);
    }
}
