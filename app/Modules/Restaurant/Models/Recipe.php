<?php

namespace App\Modules\Restaurant\Models;

use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Recipe extends AuditableModel
{
    use SoftDeletes;

    protected $fillable = [
        'branch_id',
        'product_id',
        'name',
        'yield_quantity',
        'instructions',
        'is_active',
    ];

    protected $casts = [
        'yield_quantity' => 'decimal:3',
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

    public function items(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }
}
