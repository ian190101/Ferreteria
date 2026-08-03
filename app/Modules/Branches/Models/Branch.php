<?php

namespace App\Modules\Branches\Models;

use App\Models\User;
use App\Modules\Billing\Models\SiatBranchSetting;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends AuditableModel
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'barcode',
        'phone',
        'secondary_phone',
        'point_of_sale_name',
        'address',
        'latitude',
        'longitude',
        'maps_reference',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    public function setting(): HasOne
    {
        return $this->hasOne(BranchSetting::class);
    }

    public function siatSetting(): HasOne
    {
        return $this->hasOne(SiatBranchSetting::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
