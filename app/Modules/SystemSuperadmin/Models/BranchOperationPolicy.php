<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Branches\Models\Branch;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchOperationPolicy extends AuditableModel
{
    public const ENFORCEMENT_MONITOR = 'monitor';
    public const ENFORCEMENT_BLOCK = 'block';
    public const ENFORCEMENT_APPROVAL_REQUIRED = 'approval_required';

    protected $fillable = [
        'branch_id',
        'code',
        'name',
        'operation',
        'scope',
        'enforcement_mode',
        'applies_to_modules',
        'rules',
        'permissions',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'applies_to_modules' => 'array',
        'rules' => 'array',
        'permissions' => 'array',
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
