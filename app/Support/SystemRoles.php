<?php

namespace App\Support;

class SystemRoles
{
    public const SYSTEM_SUPERADMIN = 'sistemasuperadmin';
    public const BUSINESS_ADMIN = 'superadmin';

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
