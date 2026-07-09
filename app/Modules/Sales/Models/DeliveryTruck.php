<?php

namespace App\Modules\Sales\Models;

use App\Modules\Branches\Models\Branch;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeliveryTruck extends AuditableModel
{
    use SoftDeletes;

    protected $fillable = [
        'branch_id',
        'plate',
        'description',
        'brand',
        'model',
        'capacity',
        'is_active',
    ];

    protected $casts = [
        'capacity' => 'decimal:3',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
