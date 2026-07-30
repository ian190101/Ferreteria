<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionFormulaStage extends Model
{
    protected $fillable = [
        'production_formula_id',
        'name',
        'description',
        'estimated_minutes',
        'requires_confirmation',
        'sort_order',
    ];

    protected $casts = [
        'estimated_minutes' => 'integer',
        'requires_confirmation' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function formula(): BelongsTo
    {
        return $this->belongsTo(ProductionFormula::class, 'production_formula_id');
    }
}
