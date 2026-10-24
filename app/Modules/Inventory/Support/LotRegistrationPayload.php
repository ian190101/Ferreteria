<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

final class LotRegistrationPayload
{
    public static function rules(string $prefix = 'lot_registrations'): array
    {
        return [
            $prefix => ['sometimes', 'array', 'max:100'],
            $prefix.'.*' => ['required', 'array:lot_code,initial_kg,initial_meters,branch_id,barcode,notes'],
            $prefix.'.*.branch_id' => ['required', 'integer', 'exists:branches,id'],
            $prefix.'.*.lot_code' => self::codeRules(),
            $prefix.'.*.initial_kg' => ['nullable', 'numeric', 'min:0', 'max:999999999999.999', 'regex:/^\d+(?:\.\d+)?$/D'],
            $prefix.'.*.initial_meters' => ['nullable', 'numeric', 'min:0', 'max:999999999999.999', 'regex:/^\d+(?:\.\d+)?$/D'],
            $prefix.'.*.barcode' => ['nullable', 'string', 'max:80', 'distinct', 'unique:product_coils,barcode'],
            $prefix.'.*.notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    public static function codeRules(): array
    {
        return ['required', 'string', 'max:80', 'regex:/^[A-Z0-9-]+$/D', 'regex:/[A-Z0-9]/'];
    }

    public static function normalize(mixed $rows): mixed
    {
        if (! is_array($rows)) {
            return $rows;
        }

        return array_map(function ($row) {
            if (is_array($row) && is_string($row['lot_code'] ?? null)) {
                $row['lot_code'] = strtoupper(trim($row['lot_code']));
            }

            return $row;
        }, $rows);
    }
}
