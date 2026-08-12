<?php

namespace App\Modules\AiAssistant\Models;

use App\Modules\Branches\Models\Branch;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiAssistantChannel extends AuditableModel
{
    public const PROVIDER_INTERNAL = 'internal';

    public const PROVIDER_TELEGRAM = 'telegram';

    public const PROVIDER_WHATSAPP = 'whatsapp';

    public const PROVIDER_META = 'meta';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'branch_id',
        'provider',
        'name',
        'status',
        'webhook_url',
        'encrypted_credentials',
        'settings',
        'allowed_scopes',
        'last_verified_at',
    ];

    protected $casts = [
        'settings' => 'array',
        'allowed_scopes' => 'array',
        'last_verified_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(AiAssistantConversation::class);
    }
}
