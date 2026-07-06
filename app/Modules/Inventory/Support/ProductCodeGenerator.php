<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\Product;
use Illuminate\Support\Str;

class ProductCodeGenerator
{
    public static function sku(?string $name = null, ?int $ignoreProductId = null): string
    {
        $base = Str::of($name ?: 'PRODUCTO')
            ->ascii()
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', '-')
            ->trim('-')
            ->limit(24, '')
            ->value() ?: 'PRODUCTO';

        return self::unique('sku', $base, $ignoreProductId, fn (string $prefix, int $attempt) => sprintf(
            '%s-%s%02d',
            $prefix,
            now()->format('ymdHis'),
            $attempt
        ));
    }

    public static function barcode(?int $ignoreProductId = null): string
    {
        return self::unique('barcode', '779', $ignoreProductId, fn (string $prefix, int $attempt) => sprintf(
            '%s%s%03d',
            $prefix,
            now()->format('ymdHis'),
            random_int(100 + $attempt, 999)
        ));
    }

    private static function unique(string $column, string $prefix, ?int $ignoreProductId, callable $candidateFactory): string
    {
        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $candidate = $candidateFactory($prefix, $attempt);
            $exists = Product::withTrashed()
                ->where($column, $candidate)
                ->when($ignoreProductId, fn ($query) => $query->whereKeyNot($ignoreProductId))
                ->exists();

            if (! $exists) {
                return $candidate;
            }
        }

        return $candidateFactory($prefix, random_int(31, 99));
    }
}
