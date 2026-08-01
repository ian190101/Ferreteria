<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Branches\Models\Branch;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DynamicDocumentTemplate extends AuditableModel
{
    use SoftDeletes;

    protected $fillable = [
        'branch_id',
        'document_type',
        'entity_type',
        'code',
        'name',
        'paper_type',
        'thermal_width_mm',
        'printer_area',
        'copies',
        'layout',
        'fields',
        'columns',
        'legal_text',
        'terms_text',
        'permissions',
        'metadata',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'thermal_width_mm' => 'integer',
        'copies' => 'integer',
        'layout' => 'array',
        'fields' => 'array',
        'columns' => 'array',
        'permissions' => 'array',
        'metadata' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
