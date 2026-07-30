<?php

namespace App\Modules\Restaurant\Models;

use App\Modules\Branches\Models\Branch;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RestaurantTable extends AuditableModel
{
    use SoftDeletes;

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_OCCUPIED = 'occupied';
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'branch_id',
        'area_name',
        'name',
        'code',
        'capacity',
        'status',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function kitchenOrders(): HasMany
    {
        return $this->hasMany(KitchenOrder::class);
    }
}
