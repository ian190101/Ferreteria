<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Models\User;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessProfileVersion extends AuditableModel
{
    use HasFactory;

    protected $fillable = [
        'business_profile_id',
        'version_number',
        'name',
        'business_type',
        'configuration',
        'applied_by',
        'applied_at',
    ];

    protected $casts = [
        'configuration' => 'array',
        'applied_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(BusinessProfile::class, 'business_profile_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
