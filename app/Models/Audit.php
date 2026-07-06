<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use OwenIt\Auditing\Models\Audit as BaseAudit;

class Audit extends BaseAudit
{
    protected static function booted(): void
    {
        static::updating(fn (Model $audit) => false);
        static::deleting(fn (Model $audit) => false);
    }

    public function user(): MorphTo
    {
        return $this->morphTo();
    }
}
