<?php

namespace App\Modules\Billing\Models;

use App\Modules\Branches\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiatSignificantEvent extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_REGISTERED = 'registered';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'branch_id',
        'siat_cufd_event_id',
        'siat_cufd_send_id',
        'event_code',
        'reception_code',
        'description',
        'started_at',
        'ended_at',
        'status',
        'siat_response',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'siat_response' => 'array',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
