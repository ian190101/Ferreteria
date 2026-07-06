<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Thickness extends AuditableModel
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'millimeters',
        'kg_to_meter_factor',
        'kg_per_meter',
        'is_active',
    ];

    protected $casts = [
        'millimeters' => 'decimal:4',
        'kg_to_meter_factor' => 'decimal:6',
        'kg_per_meter' => 'decimal:6',
        'is_active' => 'boolean',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
