<?php

namespace App\Modules\Branches\Models;

use App\Models\User;
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
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function setting(): HasOne
    {
        return $this->hasOne(BranchSetting::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
