<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Branches\Models\Branch;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerQrChannel extends AuditableModel
{
    protected $fillable = [
        'branch_id',
        'code',
        'name',
        'token',
        'context_type',
        'context_reference',
        'service_mode',
        'allowed_order_types',
        'settings',
        'expires_at',
        'requires_customer_name',
        'requires_customer_phone',
        'requires_table_or_reference',
        'is_active',
    ];

    protected $casts = [
        'allowed_order_types' => 'array',
        'settings' => 'array',
        'expires_at' => 'datetime',
        'requires_customer_name' => 'boolean',
        'requires_customer_phone' => 'boolean',
        'requires_table_or_reference' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(CustomerQrOrder::class);
    }
}
