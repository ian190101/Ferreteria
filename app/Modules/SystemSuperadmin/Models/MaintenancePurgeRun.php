<?php

declare(strict_types=1);

namespace App\Modules\SystemSuperadmin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class MaintenancePurgeRun extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['options'];

    protected $casts = ['options' => 'encrypted:array', 'result' => 'array'];
}
