<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Shared\Models\AuditableModel;

class BusinessStateDefinition extends AuditableModel
{
    protected $fillable = [
        'entity_type',
        'workflow_code',
        'code',
        'label',
        'color',
        'state_type',
        'is_initial',
        'is_final',
        'allowed_transitions',
        'required_permission',
        'entry_validations',
        'actions',
        'exit_actions',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_initial' => 'boolean',
        'is_final' => 'boolean',
        'allowed_transitions' => 'array',
        'entry_validations' => 'array',
        'actions' => 'array',
        'exit_actions' => 'array',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];
}
