<?php

namespace App\Modules\Sales\Http\Requests;

use App\Modules\Sales\Models\Sale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class VoidSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sales.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $sale = $this->route('sale');

                if (! $sale instanceof Sale) {
                    return;
                }

                if ($sale->status === 'void') {
                    $validator->errors()->add('sale', 'El documento ya se encuentra anulado.');
                }

                if ($sale->payments()->exists()) {
                    $validator->errors()->add('sale', 'Anula los pagos activos antes de anular la nota de venta.');
                }
            },
        ];
    }
}
