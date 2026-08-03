<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;

class TransportRouteCache extends Model
{
    protected $fillable = [
        'cache_key',
        'origin_latitude',
        'origin_longitude',
        'destination_latitude',
        'destination_longitude',
        'distance_meters',
        'duration_seconds',
        'provider',
        'geometry',
        'expires_at',
    ];

    protected $casts = [
        'origin_latitude' => 'decimal:7',
        'origin_longitude' => 'decimal:7',
        'destination_latitude' => 'decimal:7',
        'destination_longitude' => 'decimal:7',
        'distance_meters' => 'integer',
        'duration_seconds' => 'integer',
        'geometry' => 'array',
        'expires_at' => 'datetime',
    ];
}
