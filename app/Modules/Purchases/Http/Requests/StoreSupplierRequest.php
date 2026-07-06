<?php

namespace App\Modules\Purchases\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchases.manage') ?? false;
    }

    public function rules(): array
    {
        $taxIdRules = ['nullable', 'string', 'max:64'];

        if ($this->filled('tax_id')) {
            $taxIdRules[] = Rule::unique('suppliers', 'tax_id')->whereNull('deleted_at');
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'tax_id' => $taxIdRules,
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
