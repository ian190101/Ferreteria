<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Shared\Models\AuditableModel;

class DynamicReportTemplate extends AuditableModel
{
    protected $fillable = [
        'code',
        'name',
        'module',
        'entity_type',
        'description',
        'columns',
        'filters',
        'groupings',
        'metrics',
        'permissions',
        'metadata',
        'cache_ttl_minutes',
        'is_exportable',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'columns' => 'array',
        'filters' => 'array',
        'groupings' => 'array',
        'metrics' => 'array',
        'permissions' => 'array',
        'metadata' => 'array',
        'cache_ttl_minutes' => 'integer',
        'is_exportable' => 'boolean',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];
}
