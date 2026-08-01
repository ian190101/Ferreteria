<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DynamicFormFieldRule extends AuditableModel
{
    protected $fillable = [
        'dynamic_form_definition_id',
        'field_code',
        'label_override',
        'help_text',
        'placeholder',
        'is_required',
        'is_visible',
        'is_read_only',
        'default_value',
        'validation_rules',
        'visibility_conditions',
        'required_conditions',
        'options_override',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'is_visible' => 'boolean',
        'is_read_only' => 'boolean',
        'default_value' => 'array',
        'validation_rules' => 'array',
        'visibility_conditions' => 'array',
        'required_conditions' => 'array',
        'options_override' => 'array',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function form(): BelongsTo
    {
        return $this->belongsTo(DynamicFormDefinition::class, 'dynamic_form_definition_id');
    }
}
