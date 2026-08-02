<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\SoftDeletes;

class CalculationFormula extends AuditableModel
{
    use SoftDeletes;

    protected $fillable = [
        'entity_type',
        'code',
        'name',
        'description',
        'result_type',
        'expression',
        'variables',
        'precision',
        'permissions',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'expression' => 'array',
        'variables' => 'array',
        'precision' => 'integer',
        'permissions' => 'array',
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];
}
