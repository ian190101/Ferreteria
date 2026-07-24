<?php

namespace App\Modules\Billing\Models;

use App\Modules\Sales\Models\SaleItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiatInvoiceItem extends Model
{
    protected $fillable = [
        'siat_invoice_id',
        'sale_item_id',
        'economic_activity_code',
        'sin_product_code',
        'product_code',
        'description',
        'quantity',
        'unit_measure_code',
        'unit_price',
        'discount_amount',
        'subtotal',
        'serial_number',
        'imei_number',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'discount_amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SiatInvoice::class, 'siat_invoice_id');
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }
}
