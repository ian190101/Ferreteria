<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\Lots;

use App\Modules\Inventory\Support\LotRegistrationPayload;
use Illuminate\Foundation\Http\FormRequest;

class RegisterProductLotsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.products.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['lot_registrations' => LotRegistrationPayload::normalize($this->input('lot_registrations'))]);
    }

    public function rules(): array
    {
        return array_replace(LotRegistrationPayload::rules(), ['lot_registrations' => ['required', 'array', 'min:1', 'max:100']]);
    }
}
