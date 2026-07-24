<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\SiatBranchSetting;
use Carbon\CarbonInterface;

class SiatCufGenerator
{
    public function generate(SiatBranchSetting $setting, int|string $invoiceNumber, CarbonInterface $issuedAt, string $controlCode): string
    {
        $payload = implode('', [
            str_pad($setting->nit, 13, '0', STR_PAD_LEFT),
            $issuedAt->format('YmdHisv'),
            str_pad((string) $setting->siat_branch_code, 4, '0', STR_PAD_LEFT),
            $setting->modality_code,
            $setting->emission_type_code,
            $setting->invoice_type_code,
            str_pad((string) $setting->document_sector_code, 2, '0', STR_PAD_LEFT),
            str_pad((string) $invoiceNumber, 10, '0', STR_PAD_LEFT),
            str_pad((string) $setting->point_of_sale_code, 4, '0', STR_PAD_LEFT),
        ]);

        $payloadWithCheckDigit = $payload.$this->mod11($payload);

        return strtoupper(base_convert($payloadWithCheckDigit, 10, 16)).$controlCode;
    }

    private function mod11(string $value): int
    {
        $sum = 0;
        $multiplier = 2;

        for ($index = strlen($value) - 1; $index >= 0; $index--) {
            $sum += (int) $value[$index] * $multiplier;
            $multiplier = $multiplier === 9 ? 2 : $multiplier + 1;
        }

        $remainder = $sum % 11;
        $digit = 11 - $remainder;

        return match ($digit) {
            10 => 1,
            11 => 0,
            default => $digit,
        };
    }
}
