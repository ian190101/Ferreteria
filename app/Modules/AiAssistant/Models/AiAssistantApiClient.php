<?php

namespace App\Modules\AiAssistant\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAssistantApiClient extends AuditableModel
{
    protected $fillable = [
        'branch_id',
        'user_id',
        'name',
        'token_hash',
        'scopes',
        'status',
        'rate_limit_per_minute',
        'last_used_at',
    ];

    protected $casts = [
        'scopes' => 'array',
        'last_used_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
