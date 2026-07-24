<?php

namespace App\Modules\Billing\Models;

use App\Modules\Branches\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SiatPackage extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_OBSERVED = 'observed';

    protected $fillable = [
        'branch_id',
        'siat_significant_event_id',
        'invoice_count',
        'reception_code',
        'hash',
        'gzip_base64',
        'status',
        'siat_response',
        'sent_at',
        'validated_at',
    ];

    protected $casts = [
        'siat_response' => 'array',
        'sent_at' => 'datetime',
        'validated_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(SiatSignificantEvent::class, 'siat_significant_event_id');
    }

    public function invoices(): BelongsToMany
    {
        return $this->belongsToMany(SiatInvoice::class, 'siat_package_invoice')
            ->withPivot('file_number')
            ->withTimestamps();
    }
}
