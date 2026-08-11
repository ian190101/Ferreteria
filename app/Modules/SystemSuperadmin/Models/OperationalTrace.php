<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalTrace extends AuditableModel
{
    protected $fillable = [
        'entity_type',
        'entity_id',
        'event',
        'actor_id',
        'branch_id',
        'source',
        'status',
        'metadata',
        'context',
        'occurred_at',
    ];

    protected $casts = [
        'entity_id' => 'integer',
        'metadata' => 'array',
        'context' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
