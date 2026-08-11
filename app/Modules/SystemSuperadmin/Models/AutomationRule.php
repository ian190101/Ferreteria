<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationRule extends AuditableModel
{
    protected $fillable = [
        'code',
        'name',
        'entity_type',
        'trigger',
        'conditions',
        'actions',
        'cooldown_minutes',
        'last_run_at',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'conditions' => 'array',
        'actions' => 'array',
        'cooldown_minutes' => 'integer',
        'last_run_at' => 'datetime',
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }
}
