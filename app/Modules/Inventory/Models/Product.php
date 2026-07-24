<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Billing\Models\SiatProductMapping;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends AuditableModel
{
    use SoftDeletes;

    public const TRACKING_GLOBAL = 'global';

    public const TRACKING_COIL = 'coil';

    protected $fillable = [
        'thickness_id',
        'product_category_id',
        'product_unit_id',
        'name',
        'category',
        'sku',
        'barcode',
        'inventory_tracking_mode',
        'base_unit',
        'attributes',
        'custom_attributes',
        'allowed_units',
        'purchase_price',
        'sale_price',
        'minimum_stock_meters',
        'is_active',
    ];

    protected $casts = [
        'attributes' => 'array',
        'custom_attributes' => 'array',
        'allowed_units' => 'array',
        'purchase_price' => 'decimal:4',
        'sale_price' => 'decimal:4',
        'minimum_stock_meters' => 'decimal:3',
        'is_active' => 'boolean',
    ];

    public function thickness(): BelongsTo
    {
        return $this->belongsTo(Thickness::class);
    }

    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }

    public function branchStocks(): HasMany
    {
        return $this->hasMany(ProductBranchStock::class);
    }

    public function coils(): HasMany
    {
        return $this->hasMany(ProductCoil::class);
    }

    public function unitConversions(): HasMany
    {
        return $this->hasMany(ProductUnitConversion::class);
    }

    public function siatMapping(): HasOne
    {
        return $this->hasOne(SiatProductMapping::class);
    }
}
