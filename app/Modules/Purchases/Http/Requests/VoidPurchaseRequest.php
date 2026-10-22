<?php

namespace App\Modules\Purchases\Http\Requests;

use App\Modules\Purchases\Models\Purchase;
use App\Modules\Purchases\Services\PurchaseVoidAuthorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class VoidPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $purchase = $this->route('purchase');

        return $this->user() && $purchase instanceof Purchase
            && app(PurchaseVoidAuthorization::class)->allows($this->user(), $purchase);
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
                $purchase = $this->route('purchase');

                if (! $purchase instanceof Purchase) {
                    return;
                }

                if ($purchase->status === 'voided' || $purchase->status === 'void') {
                    $validator->errors()->add('purchase', 'La compra ya se encuentra anulada.');
                }
            },
        ];
    }
}
