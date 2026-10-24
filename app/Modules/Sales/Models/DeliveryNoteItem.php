<?php

namespace App\Modules\Sales\Models;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryNoteItem extends AuditableModel
{
    protected $fillable = [
        'delivery_note_id',
        'sale_item_id',
        'product_id',
        'product_coil_id',
        'lot_code_snapshot',
        'meters',
        'display_quantity',
        'display_unit_label',
    ];

    protected $casts = [
        'meters' => 'decimal:3',
        'display_quantity' => 'decimal:3',
    ];

    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
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
