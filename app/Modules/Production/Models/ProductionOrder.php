<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionOrder extends AuditableModel
{
    use SoftDeletes;

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'branch_id',
        'user_id',
        'production_formula_id',
        'input_product_id',
        'input_product_coil_id',
        'output_product_id',
        'output_product_coil_id',
        'order_number',
        'produced_at',
        'input_meters',
        'output_meters',
        'planned_output_quantity',
        'actual_output_quantity',
        'waste_meters',
        'labor_cost',
        'overhead_cost',
        'input_cost',
        'total_cost',
        'unit_cost',
        'output_coil_barcode',
        'output_lot_number',
        'status',
        'notes',
    ];

    protected $casts = [
        'produced_at' => 'datetime',
        'input_meters' => 'decimal:3',
        'output_meters' => 'decimal:3',
        'planned_output_quantity' => 'decimal:4',
        'actual_output_quantity' => 'decimal:4',
        'waste_meters' => 'decimal:3',
        'labor_cost' => 'decimal:4',
        'overhead_cost' => 'decimal:4',
        'input_cost' => 'decimal:4',
        'total_cost' => 'decimal:4',
        'unit_cost' => 'decimal:4',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function formula(): BelongsTo
    {
        return $this->belongsTo(ProductionFormula::class, 'production_formula_id');
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

    public function inputs(): HasMany
    {
        return $this->hasMany(ProductionOrderInput::class)->with(['product:id,name,sku,base_unit', 'coil:id,barcode,lot_number']);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(ProductionOrderStage::class)->orderBy('sort_order');
    }
}
