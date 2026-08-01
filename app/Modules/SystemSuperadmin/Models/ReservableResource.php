<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Branches\Models\Branch;
use App\Modules\HumanResources\Models\Worker;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservableResource extends AuditableModel
{
    protected $fillable = [
        'branch_id',
        'assigned_worker_id',
        'type',
        'code',
        'name',
        'capacity',
        'status',
        'location',
        'maintenance_starts_at',
        'maintenance_ends_at',
        'availability_rules',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'maintenance_starts_at' => 'datetime',
        'maintenance_ends_at' => 'datetime',
        'availability_rules' => 'array',
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function assignedWorker(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'assigned_worker_id');
    }
}
