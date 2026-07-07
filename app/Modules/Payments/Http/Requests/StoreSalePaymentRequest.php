<?php

namespace App\Modules\Payments\Http\Requests;

use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Cash\Support\CashSessionGuard;
use App\Modules\Sales\Models\Sale;
use App\Support\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;

class StoreSalePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('payments.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'sale_id' => ['required', 'integer', 'exists:sales,id'],
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
            $sale = Sale::query()->find($this->integer('sale_id'));
            $method = PaymentMethod::query()->find($this->integer('payment_method_id'));

            if (! $sale || ! $method) {
                return;
            }

            if ($message = BranchAccess::validate($this->user(), (int) $sale->branch_id)) {
                $validator->errors()->add('sale_id', $message);

                return;
            }

            if (CashSessionGuard::requiresOpenSession($this->user(), (int) $sale->branch_id)) {
                $validator->errors()->add('sale_id', CashSessionGuard::message());

                return;
            }

            if ($sale->document_type !== 'sale_note') {
                $validator->errors()->add('sale_id', 'Solo las notas de venta pueden recibir pagos.');
            }

            if ((float) $sale->balance_due <= 0) {
                $validator->errors()->add('amount', 'La nota de venta ya no tiene saldo pendiente.');
            }

            if ((float) $this->input('amount', 0) > (float) $sale->balance_due) {
                $validator->errors()->add('amount', 'El pago no puede ser mayor al saldo pendiente.');
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
