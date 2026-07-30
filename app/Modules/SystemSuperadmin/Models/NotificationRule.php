<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Shared\Models\AuditableModel;

class NotificationRule extends AuditableModel
{
    protected $fillable = [
        'code',
        'name',
        'trigger',
        'channels',
        'recipients',
        'conditions',
        'is_active',
    ];

    protected $casts = [
        'channels' => 'array',
        'recipients' => 'array',
        'conditions' => 'array',
        'is_active' => 'boolean',
    ];
}
