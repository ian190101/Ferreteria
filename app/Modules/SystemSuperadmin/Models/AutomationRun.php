<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationRun extends AuditableModel
{
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'automation_rule_id',
        'entity_type',
        'entity_id',
        'trigger',
        'status',
        'context',
        'actions',
        'result_message',
        'executed_at',
    ];

    protected $casts = [
        'entity_id' => 'integer',
        'context' => 'array',
        'actions' => 'array',
        'executed_at' => 'datetime',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }
}
