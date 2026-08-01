<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DynamicFormDefinition extends AuditableModel
{
    protected $fillable = [
        'entity_type',
        'code',
        'name',
        'flow',
        'workflow_code',
        'state_code',
        'surface',
        'description',
        'submit_label',
        'layout',
        'permissions',
        'validations',
        'metadata',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'layout' => 'array',
        'permissions' => 'array',
        'validations' => 'array',
        'metadata' => 'array',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function fieldRules(): HasMany
    {
        return $this->hasMany(DynamicFormFieldRule::class);
    }
}
