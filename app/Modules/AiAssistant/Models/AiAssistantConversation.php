<?php

namespace App\Modules\AiAssistant\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiAssistantConversation extends AuditableModel
{
    protected $fillable = [
        'branch_id',
        'user_id',
        'ai_assistant_channel_id',
        'subject_type',
        'external_user_ref',
        'status',
        'last_intent',
        'context',
        'last_message_at',
    ];

    protected $casts = [
        'context' => 'array',
        'last_message_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(AiAssistantChannel::class, 'ai_assistant_channel_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiAssistantMessage::class);
    }
}
