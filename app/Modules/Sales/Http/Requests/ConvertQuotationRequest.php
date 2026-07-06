<?php

namespace App\Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConvertQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sales.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'receipt_number' => ['nullable', 'string', 'max:80', 'unique:sales,receipt_number'],
            'sold_at' => ['nullable', 'date'],
        ];
    }
}
