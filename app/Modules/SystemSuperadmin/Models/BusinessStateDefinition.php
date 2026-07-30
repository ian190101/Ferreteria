<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Shared\Models\AuditableModel;

class BusinessStateDefinition extends AuditableModel
{
    protected $fillable = [
        'entity_type',
        'code',
        'label',
        'color',
        'is_initial',
        'is_final',
        'allowed_transitions',
        'required_permission',
        'actions',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_initial' => 'boolean',
        'is_final' => 'boolean',
        'allowed_transitions' => 'array',
        'actions' => 'array',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];
}
