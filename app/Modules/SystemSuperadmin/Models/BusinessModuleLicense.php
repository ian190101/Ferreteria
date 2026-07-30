<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Shared\Models\AuditableModel;

class BusinessModuleLicense extends AuditableModel
{
    protected $fillable = [
        'module',
        'is_enabled',
        'max_branches',
        'max_users',
        'max_pos_terminals',
        'support_until',
        'metadata',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'max_branches' => 'integer',
        'max_users' => 'integer',
        'max_pos_terminals' => 'integer',
        'support_until' => 'date',
        'metadata' => 'array',
    ];
}
