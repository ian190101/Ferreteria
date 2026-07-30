<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionRule extends AuditableModel
{
    protected $fillable = [
        'branch_id',
        'role_name',
        'responsible_type',
        'product_id',
        'calculation_base',
        'type',
        'value',
        'conditions',
        'is_active',
    ];

    protected $casts = [
        'value' => 'decimal:4',
        'conditions' => 'array',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
