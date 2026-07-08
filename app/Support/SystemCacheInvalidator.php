<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class SystemCacheInvalidator
{
    private const OPERATIONAL_VERSION_KEY = 'system-cache:operational-version';

    public static function operationalVersion(): string
    {
        return (string) Cache::get(self::OPERATIONAL_VERSION_KEY, '1');
    }

    public static function bumpOperational(): void
    {
        Cache::forever(self::OPERATIONAL_VERSION_KEY, now()->format('Uu'));
    }
}
