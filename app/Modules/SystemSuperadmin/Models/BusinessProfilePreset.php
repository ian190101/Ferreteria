<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Models\User;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BusinessProfilePreset extends AuditableModel
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'business_type',
        'description',
        'is_system',
        'configuration',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'configuration' => 'array',
        'is_system' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
