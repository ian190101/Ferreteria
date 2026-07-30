<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Shared\Models\AuditableModel;

class CustomFieldDefinition extends AuditableModel
{
    protected $fillable = [
        'entity_type',
        'code',
        'label',
        'type',
        'options',
        'validation_rules',
        'is_required',
        'visible_in_forms',
        'visible_in_documents',
        'visible_in_reports',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'options' => 'array',
        'validation_rules' => 'array',
        'is_required' => 'boolean',
        'visible_in_forms' => 'boolean',
        'visible_in_documents' => 'boolean',
        'visible_in_reports' => 'boolean',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];
}
