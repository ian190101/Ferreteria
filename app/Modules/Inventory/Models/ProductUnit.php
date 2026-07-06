<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductUnit extends AuditableModel
{
    protected $fillable = [
        'name',
        'symbol',
        'kind',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
