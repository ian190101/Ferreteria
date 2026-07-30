<?php

namespace App\Modules\SystemSuperadmin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessProfileCapability extends Model
{
    protected $fillable = [
        'business_profile_id',
        'business_capability_id',
        'is_enabled',
        'source',
        'settings',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'settings' => 'array',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(BusinessProfile::class, 'business_profile_id');
    }

    public function capability(): BelongsTo
    {
        return $this->belongsTo(BusinessCapability::class, 'business_capability_id');
    }
}
