<?php

namespace App\Modules\Reservations\Models;

use App\Modules\Inventory\Models\Product;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessReservationItem extends AuditableModel
{
    protected $fillable = [
        'business_reservation_id',
        'product_id',
        'description',
        'quantity',
        'unit_label',
        'unit_price',
        'subtotal',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'subtotal' => 'decimal:4',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(BusinessReservation::class, 'business_reservation_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
