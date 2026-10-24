<?php

namespace App\Modules\Purchases\Models;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderReceiptItem extends AuditableModel
{
    protected $fillable = [
        'purchase_order_receipt_id',
        'purchase_order_item_id',
        'product_id',
        'product_coil_id',
        'lot_code_snapshot',
        'coil_barcode',
        'kilograms',
        'meters',
        'unit_cost',
        'line_total',
    ];

    protected $casts = [
        'kilograms' => 'decimal:3',
        'meters' => 'decimal:3',
        'unit_cost' => 'decimal:4',
        'line_total' => 'decimal:2',
    ];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderReceipt::class, 'purchase_order_receipt_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function coil(): BelongsTo
    {
        return $this->belongsTo(ProductCoil::class, 'product_coil_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(ProductCoil::class, 'product_coil_id');
    }
}
