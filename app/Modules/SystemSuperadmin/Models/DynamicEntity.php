<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Shared\Models\AuditableModel;

class DynamicEntity extends AuditableModel
{
    protected $fillable = [
        'code',
        'label',
        'plural_label',
        'base_entity',
        'module',
        'icon',
        'mode',
        'is_visible',
        'is_editable',
        'is_required',
        'is_exportable',
        'is_reportable',
        'is_auditable',
        'is_sensitive',
        'retention_policy',
        'permissions',
        'settings',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
        'is_editable' => 'boolean',
        'is_required' => 'boolean',
        'is_exportable' => 'boolean',
        'is_reportable' => 'boolean',
        'is_auditable' => 'boolean',
        'is_sensitive' => 'boolean',
        'permissions' => 'array',
        'settings' => 'array',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];
}
