<?php

namespace App\Support;

use App\Modules\Settings\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;

class DecimalPrecision
{
    public const SETTING_KEY = 'decimal_precision';

    public static function config(): array
    {
        return Cache::remember('system:decimal-precision:v1', now()->addMinutes(30), function () {
            $setting = SystemSetting::query()
                ->where('key', self::SETTING_KEY)
                ->first(['value']);

            return self::normalize(is_array($setting?->value) ? $setting->value : []);
        });
    }

    public static function forget(): void
    {
        Cache::forget('system:decimal-precision:v1');
    }

    public static function defaults(): array
    {
        return [
            'quantity' => 0,
            'measure' => 2,
            'money' => 1,
            'percent' => 2,
            'exchange_rate' => 6,
            'weight' => 2,
            'cost' => 1,
            'modules' => [
                'sales' => [
                    'quantity' => 0,
                    'measure' => 2,
                    'money' => 1,
                ],
                'purchases' => [
                    'quantity' => 0,
                    'measure' => 2,
                    'money' => 1,
                    'weight' => 2,
                    'cost' => 1,
                ],
                'inventory' => [
                    'quantity' => 0,
                    'measure' => 2,
                    'weight' => 2,
                    'cost' => 1,
                ],
                'finance' => [
                    'money' => 1,
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

        foreach (($normalized['modules'] ?? []) as $module => $rules) {
            foreach ($rules as $key => $decimals) {
                $normalized['modules'][$module][$key] = self::bounded($decimals);
            }
        }

        return $normalized;
    }

    private static function bounded(mixed $value): int
    {
        return max(0, min(6, (int) $value));
    }
}
