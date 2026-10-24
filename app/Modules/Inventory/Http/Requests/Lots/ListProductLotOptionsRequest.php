<?php

namespace App\Modules\Inventory\Http\Requests\Lots;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListProductLotOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = match ($this->input('context')) {
            'purchase', 'receipt' => 'purchases.manage',
            'adjustment' => 'inventory.adjustments.manage',
            'reservation' => 'inventory.reservations.manage',
            default => 'sales.manage',
        };

        return $this->user()?->can($permission) === true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'context' => ['required', Rule::in(['purchase', 'receipt', 'quotation', 'sale', 'pos', 'adjustment', 'reservation'])],
            'search' => ['nullable', 'string', 'max:80'],
            'cursor' => ['nullable', 'string', 'max:1000'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
