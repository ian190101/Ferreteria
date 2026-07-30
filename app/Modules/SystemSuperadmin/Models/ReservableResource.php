<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Branches\Models\Branch;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservableResource extends AuditableModel
{
    protected $fillable = [
        'branch_id',
        'type',
        'code',
        'name',
        'capacity',
        'availability_rules',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'availability_rules' => 'array',
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
