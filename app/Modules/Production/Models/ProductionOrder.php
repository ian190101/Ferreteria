<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionOrder extends AuditableModel
{
    use SoftDeletes;

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'branch_id',
        'user_id',
        'input_product_id',
        'input_product_coil_id',
        'output_product_id',
        'output_product_coil_id',
        'order_number',
        'produced_at',
        'input_meters',
        'output_meters',
        'waste_meters',
        'output_coil_barcode',
        'output_lot_number',
        'status',
        'notes',
    ];

    protected $casts = [
        'produced_at' => 'datetime',
        'input_meters' => 'decimal:3',
        'output_meters' => 'decimal:3',
        'waste_meters' => 'decimal:3',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function inputProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'input_product_id');
    }

    public function inputCoil(): BelongsTo
    {
        return $this->belongsTo(ProductCoil::class, 'input_product_coil_id');
    }

    public function outputProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'output_product_id');
    }

    public function outputCoil(): BelongsTo
    {
        return $this->belongsTo(ProductCoil::class, 'output_product_coil_id');
    }
}
