<?php

namespace App\Modules\Inventory\Http\Requests\Lots;

use App\Modules\Inventory\Services\LotAuthorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lot = $this->route('lot');

        return $lot && app(LotAuthorization::class)->canManage($this->user(), $lot);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('lot_code')) {
            $this->merge(['lot_code' => strtoupper(trim((string) $this->input('lot_code')))]);
        }
    }

    public function rules(): array
    {
        $lot = $this->route('lot');

        return [
            'lot_code' => [
                ...\App\Modules\Inventory\Support\LotRegistrationPayload::codeRules(),
                Rule::unique('product_coils', 'lot_code')
                    ->where('product_id', $lot->product_id)
                    ->where('branch_id', $lot->branch_id)
                    ->ignore($lot->id),
            ],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
