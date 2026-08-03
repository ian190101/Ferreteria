<?php

namespace App\Modules\ServiceOrders\Models;

use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ServiceType extends AuditableModel
{
    use SoftDeletes;

    public const DELIVERY_CODE = 'transport_delivery';

    protected $fillable = [
        'code',
        'name',
        'description',
        'billing_unit',
        'requires_materials',
        'requires_responsible',
        'requires_schedule',
        'is_delivery',
        'is_system',
        'is_active',
        'settings',
        'sort_order',
    ];

    protected $casts = [
        'requires_materials' => 'boolean',
        'requires_responsible' => 'boolean',
        'requires_schedule' => 'boolean',
        'is_delivery' => 'boolean',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'settings' => 'array',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (ServiceType $type) {
            $type->code = $type->code ?: Str::slug($type->name, '_');
        });
    }

    public function canBeDeleted(): bool
    {
        return ! $this->is_system;
    }
}
