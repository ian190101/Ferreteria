<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Branches\Models\Branch;
use App\Modules\Customers\Models\Customer;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceList extends AuditableModel
{
    protected $fillable = [
        'branch_id',
        'customer_id',
        'code',
        'name',
        'channel',
        'currency_code',
        'starts_at',
        'ends_at',
        'rules',
        'is_active',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'rules' => 'array',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }
}
