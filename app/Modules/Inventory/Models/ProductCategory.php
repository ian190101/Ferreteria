<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ProductCategory extends AuditableModel
{
    protected $fillable = [
        'default_unit_id',
        'name',
        'slug',
        'description',
        'default_tracking_mode',
        'requires_thickness',
        'is_active',
    ];

    protected $casts = [
        'requires_thickness' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (ProductCategory $category) {
            $category->slug = $category->slug ?: Str::slug($category->name);
        });
    }

    public function defaultUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'default_unit_id');
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(ProductCategoryAttribute::class)->orderBy('sort_order')->orderBy('name');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
