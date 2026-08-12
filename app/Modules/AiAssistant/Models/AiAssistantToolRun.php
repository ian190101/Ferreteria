<?php

namespace App\Modules\AiAssistant\Models;

use App\Models\User;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAssistantToolRun extends AuditableModel
{
    protected $fillable = [
        'ai_assistant_conversation_id',
        'ai_assistant_message_id',
        'user_id',
        'tool',
        'status',
        'input',
        'output',
        'error',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'input' => 'array',
        'output' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiAssistantConversation::class, 'ai_assistant_conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
