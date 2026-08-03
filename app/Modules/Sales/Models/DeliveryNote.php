<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeliveryNote extends AuditableModel
{
    use SoftDeletes;

    protected $fillable = [
        'sale_id',
        'branch_id',
        'user_id',
        'delivery_driver_id',
        'delivery_truck_id',
        'manual_driver',
        'manual_truck',
        'delivery_number',
        'delivered_at',
        'total_meters',
        'recipient_name',
        'recipient_document',
        'recipient_phone',
        'driver_name',
        'vehicle_plate',
        'origin_address',
        'origin_latitude',
        'origin_longitude',
        'destination_address',
        'destination_latitude',
        'destination_longitude',
        'route_distance_meters',
        'route_duration_seconds',
        'route_provider',
        'route_cache_key',
        'route_geometry',
        'status',
        'notes',
    ];

    protected $casts = [
        'delivered_at' => 'datetime',
        'total_meters' => 'decimal:3',
        'manual_driver' => 'boolean',
        'manual_truck' => 'boolean',
        'origin_latitude' => 'decimal:7',
        'origin_longitude' => 'decimal:7',
        'destination_latitude' => 'decimal:7',
        'destination_longitude' => 'decimal:7',
        'route_distance_meters' => 'integer',
        'route_duration_seconds' => 'integer',
        'route_geometry' => 'array',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(DeliveryDriver::class, 'delivery_driver_id');
    }

    public function truck(): BelongsTo
    {
        return $this->belongsTo(DeliveryTruck::class, 'delivery_truck_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryNoteItem::class);
    }
}
