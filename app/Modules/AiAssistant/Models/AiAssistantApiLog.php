<?php

namespace App\Modules\AiAssistant\Models;

use App\Models\User;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAssistantApiLog extends AuditableModel
{
    protected $fillable = [
        'ai_assistant_api_client_id',
        'user_id',
        'endpoint',
        'scope',
        'status',
        'http_status',
        'ip_hash',
        'user_agent_hash',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(AiAssistantApiClient::class, 'ai_assistant_api_client_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
