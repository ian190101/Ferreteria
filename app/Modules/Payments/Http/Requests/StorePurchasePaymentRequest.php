<?php

namespace App\Modules\Payments\Http\Requests;

use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Purchases\Models\Purchase;
use App\Support\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;

class StorePurchasePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('payments.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'purchase_id' => ['required', 'integer', 'exists:purchases,id'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'paid_at' => ['nullable', 'date'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.99'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $purchase = Purchase::query()->find($this->integer('purchase_id'));
            $method = PaymentMethod::query()->find($this->integer('payment_method_id'));

            if (! $purchase || ! $method) {
                return;
            }

            if ($message = BranchAccess::validate($this->user(), (int) $purchase->branch_id)) {
                $validator->errors()->add('purchase_id', $message);

                return;
            }

            if ((float) $purchase->balance_due <= 0) {
                $validator->errors()->add('amount', 'La compra ya no tiene saldo pendiente.');
            }

            if ((float) $this->input('amount', 0) > (float) $purchase->balance_due) {
                $validator->errors()->add('amount', 'El pago no puede ser mayor al saldo pendiente de la compra.');
            }

            if (! $method->is_active) {
                $validator->errors()->add('payment_method_id', 'El metodo de pago no esta activo.');
            }

            if ($method->requires_reference && blank($this->input('reference'))) {
                $validator->errors()->add('reference', 'La referencia es obligatoria para este metodo de pago.');
            }
        });
    }
}
