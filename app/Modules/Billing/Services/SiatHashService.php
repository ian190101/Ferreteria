<?php

namespace App\Modules\Billing\Services;

class SiatHashService
{
    public function sha256(string $contents): string
    {
        return hash('sha256', $contents);
    }
}
