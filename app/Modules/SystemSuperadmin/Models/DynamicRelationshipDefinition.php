<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DynamicRelationshipDefinition extends AuditableModel
{
    protected $fillable = [
        'code',
        'label',
        'source_entity_type',
        'target_entity_type',
        'type',
        'inverse_label',
        'is_required',
        'allows_multiple',
        'min_items',
        'max_items',
        'cascade_behavior',
        'permissions',
        'metadata',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'allows_multiple' => 'boolean',
        'min_items' => 'integer',
        'max_items' => 'integer',
        'permissions' => 'array',
        'metadata' => 'array',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function values(): HasMany
    {
        return $this->hasMany(DynamicRelationshipValue::class);
    }
}
