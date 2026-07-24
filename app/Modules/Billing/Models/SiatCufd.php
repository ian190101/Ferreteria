<?php

namespace App\Modules\Billing\Models;

use App\Modules\Branches\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiatCufd extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    protected $table = 'siat_cufd';

    protected $fillable = [
        'branch_id',
        'siat_cuis_id',
        'code',
        'control_code',
        'address',
        'valid_from',
        'valid_until',
        'status',
        'response',
    ];

    protected $casts = [
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'response' => 'array',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function cuis(): BelongsTo
    {
        return $this->belongsTo(SiatCuis::class, 'siat_cuis_id');
    }
}
