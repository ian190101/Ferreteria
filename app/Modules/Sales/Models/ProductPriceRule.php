<?php

namespace App\Modules\Sales\Models;

use App\Modules\Branches\Models\Branch;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPriceRule extends AuditableModel
{
    protected $fillable = [
        'product_id',
        'branch_id',
        'customer_id',
        'price',
        'is_active',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'price' => 'decimal:4',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
