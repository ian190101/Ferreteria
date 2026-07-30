<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Shared\Models\AuditableModel;

class ImportProfileTemplate extends AuditableModel
{
    protected $fillable = [
        'code',
        'name',
        'entity_type',
        'required_columns',
        'optional_columns',
        'mapping_rules',
        'validation_rules',
        'is_active',
    ];

    protected $casts = [
        'required_columns' => 'array',
        'optional_columns' => 'array',
        'mapping_rules' => 'array',
        'validation_rules' => 'array',
        'is_active' => 'boolean',
    ];
}
