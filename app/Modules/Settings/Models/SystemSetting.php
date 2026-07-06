<?php

namespace App\Modules\Settings\Models;

use App\Modules\Shared\Models\AuditableModel;

class SystemSetting extends AuditableModel
{
    protected $fillable = [
        'group',
        'key',
        'value',
        'description',
        'is_public',
    ];

    protected $casts = [
        'value' => 'array',
        'is_public' => 'boolean',
    ];
}
