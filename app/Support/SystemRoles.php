<?php

namespace App\Support;

class SystemRoles
{
    public const SYSTEM_SUPERADMIN = 'sistemasuperadmin';

    /**
     * @return array<int, string>
     */
    public static function reserved(): array
    {
        return [
            self::SYSTEM_SUPERADMIN,
        ];
    }
}
