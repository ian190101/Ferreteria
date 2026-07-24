<?php

namespace App\Modules\Sales\Services;

use App\Modules\Settings\Models\SystemSetting;
use App\Modules\SystemSuperadmin\Services\ActiveBusinessProfile;
use Illuminate\Support\Facades\Cache;

class DocumentItemMergePolicy
{
    public const SETTING_KEY = 'sales_item_merge';

    public function controlEnabled(): bool
    {
        return (bool) (ActiveBusinessProfile::payload()['sales']['item_merge_control_enabled'] ?? true);
    }

    public function rule(): string
    {
        return (string) (ActiveBusinessProfile::payload()['sales']['item_merge_rule'] ?? 'same_product_unit_and_measure');
    }

    public function allowSameProductDifferentMeasure(): bool
    {
        if (! $this->controlEnabled()) {
            return $this->rule() === 'same_product_unit_and_measure' || $this->rule() === 'never';
        }

        return (bool) ($this->setting()['allow_same_product_different_measure'] ?? true);
    }

    public function setting(): array
    {
        return Cache::remember('sales:item-merge-setting:v1', now()->addMinutes(30), function () {
            $setting = SystemSetting::query()
                ->where('key', self::SETTING_KEY)
                ->first(['value']);

            return self::normalize(is_array($setting?->value) ? $setting->value : []);
        });
    }

    public static function defaults(): array
    {
        return [
            'allow_same_product_different_measure' => true,
        ];
    }

    public static function normalize(array $value): array
    {
        return array_replace(self::defaults(), [
            'allow_same_product_different_measure' => (bool) ($value['allow_same_product_different_measure'] ?? true),
        ]);
    }

    public static function forget(): void
    {
        Cache::forget('sales:item-merge-setting:v1');
    }

    public function summary(): array
    {
        return [
            'controlEnabled' => $this->controlEnabled(),
            'rule' => $this->rule(),
            'allowSameProductDifferentMeasure' => $this->allowSameProductDifferentMeasure(),
        ];
    }
}
