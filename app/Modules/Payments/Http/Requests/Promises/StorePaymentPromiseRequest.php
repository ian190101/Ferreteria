<?php

namespace App\Modules\Payments\Http\Requests\Promises;

use App\Modules\Sales\Models\Sale;
use App\Support\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;

class StorePaymentPromiseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('payment-promises.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'sale_id' => ['required', 'integer', 'exists:sales,id'],
            'promise_number' => ['required', 'string', 'max:80', 'unique:payment_promises,promise_number'],
            'promised_date' => ['required', 'date'],
            'promised_amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.99'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'channel' => ['required', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $sale = Sale::query()->find($this->integer('sale_id'));

            if (! $sale) {
                return;
            }

            if ($message = BranchAccess::validate($this->user(), $sale->branch_id)) {
                $validator->errors()->add('sale_id', $message);

                return;
            }

            if ($sale->document_type !== 'sale_note' || $sale->status === 'void') {
                $validator->errors()->add('sale_id', 'Solo se pueden registrar promesas para notas de venta vigentes.');
            }

            if ((float) $sale->balance_due <= 0) {
                $validator->errors()->add('promised_amount', 'La nota de venta no tiene saldo pendiente.');
            }

            if ((float) $this->input('promised_amount', 0) > (float) $sale->balance_due) {
                $validator->errors()->add('promised_amount', 'La promesa no puede superar el saldo pendiente.');
            }
        });
    }
}
