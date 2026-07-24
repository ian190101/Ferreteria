<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Models\User;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessProfile extends AuditableModel
{
    use HasFactory;

    protected $fillable = [
        'name',
        'business_type',
        'status',
        'configuration',
        'applied_at',
        'applied_by',
    ];

    protected $casts = [
        'configuration' => 'array',
        'applied_at' => 'datetime',
    ];

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
