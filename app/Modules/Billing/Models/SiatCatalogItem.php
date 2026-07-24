<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;

class SiatCatalogItem extends Model
{
    protected $fillable = [
        'catalog_type',
        'code',
        'description',
        'payload',
        'is_active',
        'synced_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'is_active' => 'boolean',
        'synced_at' => 'datetime',
    ];
}
