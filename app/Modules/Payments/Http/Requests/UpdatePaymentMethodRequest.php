<?php

namespace App\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('payments.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'code' => ['required', 'string', 'max:40', Rule::unique('payment_methods', 'code')->whereNull('deleted_at')->ignore($this->route('method'))],
            'requires_reference' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
