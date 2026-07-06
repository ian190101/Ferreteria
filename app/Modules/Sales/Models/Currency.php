<?php

namespace App\Modules\Sales\Models;

use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Currency extends AuditableModel
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'symbol',
        'exchange_rate_to_bob',
        'is_base',
        'is_active',
    ];

    protected $casts = [
        'exchange_rate_to_bob' => 'decimal:6',
        'is_base' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
