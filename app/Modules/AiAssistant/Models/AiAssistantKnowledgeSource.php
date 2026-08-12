<?php

namespace App\Modules\AiAssistant\Models;

use App\Modules\Shared\Models\AuditableModel;

class AiAssistantKnowledgeSource extends AuditableModel
{
    protected $fillable = [
        'source_type',
        'source_key',
        'title',
        'content',
        'metadata',
        'indexed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'indexed_at' => 'datetime',
    ];
}
