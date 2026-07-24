<?php

namespace App\Modules\Billing\Models;

use App\Modules\Branches\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiatLog extends Model
{
    protected $fillable = [
        'branch_id',
        'siat_invoice_id',
        'service',
        'operation',
        'status',
        'request_payload',
        'response_payload',
        'message',
        'duration_ms',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SiatInvoice::class, 'siat_invoice_id');
    }
}
