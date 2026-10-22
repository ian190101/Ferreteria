<?php

declare(strict_types=1);

namespace App\Modules\Finance\Support;

final class Decimal
{
    public static function round(string $value, int $scale): string
    {
        if ($scale < 0 || $scale > 12 || ! preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) {
            throw new \InvalidArgumentException('Decimal o escala inválidos.');
        }
        $negative = str_starts_with($value, '-');
        $magnitude = ltrim($value, '-');
        $half = $scale === 0 ? '0.5' : '0.'.str_repeat('0', $scale).'5';
        $result = bcadd($magnitude, $half, $scale);
        return $negative && bccomp($result, '0', $scale) !== 0 ? '-'.$result : $result;
    }
}
