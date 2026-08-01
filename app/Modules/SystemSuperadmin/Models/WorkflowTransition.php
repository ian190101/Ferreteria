<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowTransition extends AuditableModel
{
    protected $fillable = [
        'workflow_definition_id',
        'from_state_code',
        'to_state_code',
        'label',
        'required_permission',
        'conditions',
        'validations',
        'actions',
        'requires_reason',
        'is_reversible',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'conditions' => 'array',
        'validations' => 'array',
        'actions' => 'array',
        'requires_reason' => 'boolean',
        'is_reversible' => 'boolean',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }
}
