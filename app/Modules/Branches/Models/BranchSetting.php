<?php

namespace App\Modules\Branches\Models;

use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchSetting extends AuditableModel
{
    protected $fillable = [
        'branch_id',
        'primary_color',
        'secondary_color',
        'logo_path',
        'theme_mode',
        'options',
    ];

    protected $casts = [
        'options' => 'array',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
