<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionOrderStage extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'production_order_id',
        'name',
        'status',
        'started_at',
        'completed_at',
        'actual_minutes',
        'notes',
        'sort_order',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'actual_minutes' => 'integer',
        'sort_order' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }
}
