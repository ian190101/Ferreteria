<?php

namespace App\Modules\Payments\Http\Requests;

use App\Modules\Payments\Models\PurchasePayment;
use App\Modules\Purchases\Services\PurchaseVoidAuthorization;
use App\Support\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;

class VoidPurchasePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $payment = $this->route('payment');

        return $this->user() && $payment instanceof PurchasePayment
            && $this->user()->can('payments.manage')
            && BranchAccess::canAccess($this->user(), (int) $payment->branch_id)
            && $payment->purchase
            && app(PurchaseVoidAuthorization::class)->allows($this->user(), $payment->purchase);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
