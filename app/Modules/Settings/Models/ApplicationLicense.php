<?php

namespace App\Modules\Settings\Models;

use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApplicationLicense extends AuditableModel
{
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'holder_name',
        'nit',
        'domain',
        'license_key',
        'support_until',
        'allowed_modules',
        'activation_mode',
        'status',
        'notes',
    ];

    protected $casts = [
        'support_until' => 'date',
        'allowed_modules' => 'array',
    ];
}
