<?php

namespace App\Modules\Payments\Models;

use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentMethod extends AuditableModel
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'requires_reference',
        'is_active',
    ];

    protected $casts = [
        'requires_reference' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }
}
