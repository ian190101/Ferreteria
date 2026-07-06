<?php

namespace App\Modules\Payments\Http\Requests\CreditNotes;

use App\Modules\Payments\Models\CreditNote;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleReturn;
use App\Support\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;

class StoreCreditNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('credit-notes.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'sale_id' => ['required', 'integer', 'exists:sales,id'],
            'sale_return_id' => ['nullable', 'integer', 'exists:sale_returns,id'],
            'credit_number' => ['required', 'string', 'max:80', 'unique:credit_notes,credit_number'],
            'issued_at' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.99'],
            'reason' => ['required', 'string', 'max:255'],
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

            if ($message = BranchAccess::validate($this->user(), (int) $sale->branch_id)) {
                $validator->errors()->add('sale_id', $message);

                return;
            }

            if ($sale->document_type !== 'sale_note' || $sale->status === 'void') {
                $validator->errors()->add('sale_id', 'Solo se pueden emitir notas de credito para notas de venta vigentes.');
            }

            if ((float) $sale->balance_due <= 0) {
                $validator->errors()->add('amount', 'La nota de venta no tiene saldo pendiente.');
            }

            if ((float) $this->input('amount', 0) > (float) $sale->balance_due) {
                $validator->errors()->add('amount', 'La nota de credito no puede ser mayor al saldo pendiente.');
            }

            if (! $this->filled('sale_return_id')) {
                return;
            }

            $saleReturn = SaleReturn::query()->find($this->integer('sale_return_id'));

            if (! $saleReturn || (int) $saleReturn->sale_id !== (int) $sale->id) {
                $validator->errors()->add('sale_return_id', 'La devolucion no pertenece a la nota de venta seleccionada.');

                return;
            }

            $creditedAmount = (float) CreditNote::query()
                ->where('sale_return_id', $saleReturn->id)
                ->sum('amount');
            $availableReturnAmount = max(round((float) $saleReturn->total_amount - $creditedAmount, 2), 0);

            if ((float) $this->input('amount', 0) > $availableReturnAmount) {
                $validator->errors()->add('amount', 'La nota de credito supera el monto disponible de la devolucion.');
            }
        });
    }
}
