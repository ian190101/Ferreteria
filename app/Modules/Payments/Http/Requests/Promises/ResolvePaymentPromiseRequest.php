<?php

namespace App\Modules\Payments\Http\Requests\Promises;

use App\Modules\Payments\Models\PaymentPromise;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolvePaymentPromiseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('payment-promises.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                PaymentPromise::STATUS_FULFILLED,
                PaymentPromise::STATUS_BROKEN,
                PaymentPromise::STATUS_CANCELLED,
            ])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
