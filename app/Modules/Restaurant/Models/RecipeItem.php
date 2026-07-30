<?php

namespace App\Modules\Restaurant\Models;

use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeItem extends Model
{
    protected $fillable = [
        'recipe_id',
        'ingredient_product_id',
        'quantity',
        'unit_label',
        'waste_percentage',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'waste_percentage' => 'decimal:3',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'ingredient_product_id');
    }
}
