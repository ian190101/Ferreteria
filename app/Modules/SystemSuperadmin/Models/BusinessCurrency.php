<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Shared\Models\AuditableModel;

class BusinessCurrency extends AuditableModel
{
    protected $fillable = [
        'code',
        'name',
        'symbol',
        'exchange_rate_to_base',
        'rounding_decimals',
        'is_base',
        'cash_enabled',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'exchange_rate_to_base' => 'decimal:8',
        'rounding_decimals' => 'integer',
        'is_base' => 'boolean',
        'cash_enabled' => 'boolean',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];
}
