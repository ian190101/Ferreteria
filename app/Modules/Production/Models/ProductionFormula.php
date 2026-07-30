<?php

namespace App\Modules\Production\Models;

use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionFormula extends AuditableModel
{
    use SoftDeletes;

    protected $fillable = [
        'branch_id',
        'output_product_id',
        'code',
        'name',
        'yield_quantity',
        'expected_waste_percentage',
        'standard_labor_cost',
        'standard_overhead_cost',
        'instructions',
        'is_active',
    ];

    protected $casts = [
        'yield_quantity' => 'decimal:4',
        'expected_waste_percentage' => 'decimal:4',
        'standard_labor_cost' => 'decimal:4',
        'standard_overhead_cost' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function outputProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'output_product_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductionFormulaItem::class)->orderBy('sort_order');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(ProductionFormulaStage::class)->orderBy('sort_order');
    }
}
