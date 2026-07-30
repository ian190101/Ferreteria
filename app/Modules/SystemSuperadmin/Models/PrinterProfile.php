<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Branches\Models\Branch;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrinterProfile extends AuditableModel
{
    protected $fillable = [
        'branch_id',
        'code',
        'name',
        'area',
        'paper_type',
        'thermal_width_mm',
        'copies',
        'auto_print',
        'settings',
        'is_active',
    ];

    protected $casts = [
        'thermal_width_mm' => 'integer',
        'copies' => 'integer',
        'auto_print' => 'boolean',
        'settings' => 'array',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
