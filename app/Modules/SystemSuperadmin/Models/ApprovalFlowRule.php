<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalFlowRule extends AuditableModel
{
    protected $fillable = [
        'code',
        'name',
        'entity_type',
        'action',
        'trigger',
        'conditions',
        'approver_roles',
        'approver_permissions',
        'min_approvals',
        'requires_reason',
        'blocks_until_approved',
        'expires_after_minutes',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'conditions' => 'array',
        'approver_roles' => 'array',
        'approver_permissions' => 'array',
        'min_approvals' => 'integer',
        'requires_reason' => 'boolean',
        'blocks_until_approved' => 'boolean',
        'expires_after_minutes' => 'integer',
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    public function requests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class);
    }
}
