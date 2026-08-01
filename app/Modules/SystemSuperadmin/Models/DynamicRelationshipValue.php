<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DynamicRelationshipValue extends AuditableModel
{
    protected $fillable = [
        'dynamic_relationship_definition_id',
        'source_entity_type',
        'source_record_id',
        'target_entity_type',
        'target_record_id',
        'payload',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    protected $casts = [
        'payload' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(DynamicRelationshipDefinition::class, 'dynamic_relationship_definition_id');
    }
}
