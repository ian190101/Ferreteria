<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Models\User;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalRequest extends AuditableModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'approval_flow_rule_id',
        'entity_type',
        'entity_id',
        'action',
        'status',
        'requested_by',
        'resolved_by',
        'resolved_at',
        'expires_at',
        'reason',
        'resolution_note',
        'payload',
        'approvals',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'expires_at' => 'datetime',
        'payload' => 'array',
        'approvals' => 'array',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ApprovalFlowRule::class, 'approval_flow_rule_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
