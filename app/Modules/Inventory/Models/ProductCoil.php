<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Branches\Models\Branch;
use App\Models\User;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductCoil extends AuditableModel
{
    use SoftDeletes;

    protected $fillable = [
        'branch_id',
        'product_id',
        'barcode',
        'lot_number',
        'lot_code',
        'notes',
        'voided_at',
        'voided_by',
        'void_reason',
        'initial_meters',
        'available_meters',
        'initial_kg',
        'status',
    ];

    protected $casts = [
        'initial_meters' => 'decimal:3',
        'available_meters' => 'decimal:3',
        'initial_kg' => 'decimal:3',
        'voided_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'product_coil_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class, 'product_coil_id');
    }

    public function scopeManaged($query)
    {
        return $query->whereNotNull('lot_code');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    protected static function booted(): void
    {
        static::deleting(static function (self $lot): void {
            if ($lot->lot_code !== null) {
                throw new \LogicException('Un lote gestionado solo puede anularse, nunca eliminarse.');
            }
        });
    }
}
