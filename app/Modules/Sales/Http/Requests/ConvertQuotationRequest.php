<?php

namespace App\Modules\Sales\Http\Requests;

use App\Modules\Cash\Support\CashSessionGuard;
use App\Modules\Sales\Models\Sale;
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
            'requires_delivery' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $sale = $this->route('sale');

            if (! $sale instanceof Sale) {
                return;
            }

            if (CashSessionGuard::requiresOpenSession($this->user(), (int) $sale->branch_id)) {
                $validator->errors()->add('cash_session', CashSessionGuard::message());
            }
        });
    }
}
