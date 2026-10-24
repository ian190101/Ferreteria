<?php

namespace App\Modules\Purchases\Models;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderItem extends AuditableModel
{
    protected $fillable = [
        'purchase_order_id',
        'product_id',
        'product_coil_id',
        'coil_barcode',
        'kilograms',
        'meters',
        'received_meters',
        'unit_cost',
        'conversion_factor',
        'lot_number',
        'description',
    ];

    protected $casts = [
        'kilograms' => 'decimal:3',
        'meters' => 'decimal:3',
        'received_meters' => 'decimal:3',
        'unit_cost' => 'decimal:4',
        'conversion_factor' => 'decimal:6',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(ProductCoil::class, 'product_coil_id');
    }

    public function receiptItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderReceiptItem::class);
    }
}
