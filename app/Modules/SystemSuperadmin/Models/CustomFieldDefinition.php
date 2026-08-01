<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Shared\Models\AuditableModel;

class CustomFieldDefinition extends AuditableModel
{
    protected $fillable = [
        'entity_type',
        'code',
        'label',
        'help_text',
        'placeholder',
        'type',
        'group',
        'options',
        'validation_rules',
        'default_value',
        'format',
        'min_value',
        'max_value',
        'relation_entity_type',
        'metadata',
        'is_required',
        'visible_in_forms',
        'visible_in_table',
        'visible_in_documents',
        'visible_in_reports',
        'is_exportable',
        'is_auditable',
        'is_sensitive',
        'is_encrypted',
        'is_read_only',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'options' => 'array',
        'validation_rules' => 'array',
        'default_value' => 'array',
        'metadata' => 'array',
        'min_value' => 'decimal:6',
        'max_value' => 'decimal:6',
        'is_required' => 'boolean',
        'visible_in_forms' => 'boolean',
        'visible_in_table' => 'boolean',
        'visible_in_documents' => 'boolean',
        'visible_in_reports' => 'boolean',
        'is_exportable' => 'boolean',
        'is_auditable' => 'boolean',
        'is_sensitive' => 'boolean',
        'is_encrypted' => 'boolean',
        'is_read_only' => 'boolean',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];
}
