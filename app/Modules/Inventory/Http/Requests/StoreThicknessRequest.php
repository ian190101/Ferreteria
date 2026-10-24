<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreThicknessRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $kgPerMeter = (string) $this->input('kg_per_meter', '');
        if (preg_match('/^\d+(?:\.\d{1,6})?$/D', $kgPerMeter)
            && bccomp($kgPerMeter, '0', 6) > 0) {
            $this->merge([
                'kg_to_meter_factor' => app(\App\Modules\Inventory\Services\InventoryQuantityService::class)
                    ->divideExact('1', $kgPerMeter, 6),
            ]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('inventory.products.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'millimeters' => ['required', 'numeric', 'gt:0', 'max:9999.9999', 'unique:thicknesses,millimeters'],
            'kg_per_meter' => ['required', 'regex:/^\d+(?:\.\d{1,6})?$/D', 'numeric', 'gt:0', 'max:999999999999.999999'],
            'kg_to_meter_factor' => ['required', 'numeric', 'gt:0', 'max:999999999999.999999'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
