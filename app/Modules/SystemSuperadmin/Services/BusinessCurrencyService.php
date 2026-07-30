<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Modules\SystemSuperadmin\Models\BusinessCurrency;

class BusinessCurrencyService
{
    public function base(): ?BusinessCurrency
    {
        return BusinessCurrency::query()
            ->where('is_base', true)
            ->where('is_active', true)
            ->first();
    }

    public function convertToBase(float $amount, string $currencyCode): float
    {
        $currency = BusinessCurrency::query()
            ->where('code', strtoupper($currencyCode))
            ->where('is_active', true)
            ->first();

        if (! $currency) {
            return $amount;
        }

        return round($amount * (float) $currency->exchange_rate_to_base, $currency->rounding_decimals);
    }
}
