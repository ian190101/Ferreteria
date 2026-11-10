<?php

namespace App\Support;

use App\Modules\Settings\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;

class DecimalPrecision
{
    public const SETTING_KEY = 'decimal_precision';

    private const CACHE_KEY = 'system:decimal-precision:v2';

    private const MONEY_SCALE = 2;

    public static function config(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(30), function () {
            $setting = SystemSetting::query()
                ->where('key', self::SETTING_KEY)
                ->first(['value']);

            return self::normalize(is_array($setting?->value) ? $setting->value : []);
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public static function defaults(): array
    {
        return [
            'quantity' => 0,
            'measure' => 2,
            'money' => self::MONEY_SCALE,
            'percent' => 2,
            'exchange_rate' => 6,
            'weight' => 2,
            'cost' => self::MONEY_SCALE,
            'modules' => [
                'sales' => [
                    'quantity' => 0,
                    'measure' => 2,
                    'money' => self::MONEY_SCALE,
                    'cost' => self::MONEY_SCALE,
                ],
                'purchases' => [
                    'quantity' => 0,
                    'measure' => 2,
                    'money' => self::MONEY_SCALE,
                    'weight' => 2,
                    'cost' => self::MONEY_SCALE,
                ],
                'inventory' => [
                    'quantity' => 0,
                    'measure' => 2,
                    'weight' => 2,
                    'cost' => 1,
                ],
                'finance' => [
                    'money' => self::MONEY_SCALE,
                ],
            ],
        ];
    }

    public static function normalize(array $value): array
    {
        $defaults = self::defaults();
        $normalized = array_replace_recursive($defaults, $value);

        foreach (['quantity', 'measure', 'money', 'percent', 'exchange_rate', 'weight', 'cost'] as $key) {
            $normalized[$key] = self::bounded($normalized[$key] ?? $defaults[$key]);
        }

        if (! is_array($normalized['modules'])) {
            $normalized['modules'] = $defaults['modules'];
        }
        foreach ($normalized['modules'] as $module => $rules) {
            if (! is_array($rules)) {
                $normalized['modules'][$module] = $defaults['modules'][$module] ?? [];

                continue;
            }
            foreach ($rules as $key => $decimals) {
                $normalized['modules'][$module][$key] = self::bounded($decimals);
            }
        }

        // Los importes se capturan en centavos incluso con preferencias antiguas guardadas.
        $normalized['money'] = self::MONEY_SCALE;
        foreach (['sales', 'purchases', 'finance'] as $module) {
            $normalized['modules'][$module]['money'] = self::MONEY_SCALE;
        }
        foreach (['sales', 'purchases'] as $module) {
            $normalized['modules'][$module]['cost'] = self::MONEY_SCALE;
        }

        return $normalized;
    }

    private static function bounded(mixed $value): int
    {
        return max(0, min(6, (int) $value));
    }
}
