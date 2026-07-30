<?php

namespace App\Modules\SystemSuperadmin\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessCapability extends Model
{
    protected $fillable = [
        'code',
        'group',
        'name',
        'description',
        'recommended_business_types',
        'dependencies',
        'conflicts',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'recommended_business_types' => 'array',
        'dependencies' => 'array',
        'conflicts' => 'array',
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];
}
