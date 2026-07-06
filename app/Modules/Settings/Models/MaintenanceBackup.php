<?php

namespace App\Modules\Settings\Models;

use App\Models\User;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceBackup extends AuditableModel
{
    protected $fillable = [
        'user_id',
        'disk',
        'path',
        'status',
        'size_bytes',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
