<?php

namespace App\Modules\Production\Models;

use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionFormulaItem extends Model
{
    protected $fillable = [
        'production_formula_id',
        'input_product_id',
        'quantity',
        'unit_label',
        'waste_percentage',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'waste_percentage' => 'decimal:4',
        'sort_order' => 'integer',
    ];

    public function formula(): BelongsTo
    {
        return $this->belongsTo(ProductionFormula::class, 'production_formula_id');
    }

    public function inputProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'input_product_id');
    }
}
