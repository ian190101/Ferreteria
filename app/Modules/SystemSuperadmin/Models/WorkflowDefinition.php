<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowDefinition extends AuditableModel
{
    protected $fillable = [
        'entity_type',
        'code',
        'name',
        'description',
        'initial_state_code',
        'final_state_codes',
        'settings',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'final_state_codes' => 'array',
        'settings' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function transitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class);
    }
}
