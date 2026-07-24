<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Models\User;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessProfileDraft extends AuditableModel
{
    use HasFactory;

    protected $fillable = [
        'name',
        'business_type',
        'status',
        'configuration',
        'source_profile_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'configuration' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function sourceProfile(): BelongsTo
    {
        return $this->belongsTo(BusinessProfile::class, 'source_profile_id');
    }
}
