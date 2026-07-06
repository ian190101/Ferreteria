<?php

namespace App\Modules\Banks\Http\Requests;

use App\Modules\Banks\Models\BankAccount;
use App\Modules\Banks\Models\BankTransaction;
use App\Support\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBankTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('banks.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'bank_account_id' => ['required', 'integer', 'exists:bank_accounts,id'],
            'type' => ['required', Rule::in([BankTransaction::TYPE_DEPOSIT, BankTransaction::TYPE_WITHDRAWAL, BankTransaction::TYPE_ADJUSTMENT])],
            'transacted_at' => ['nullable', 'date'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.99'],
            'reference' => ['nullable', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:255'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $account = BankAccount::query()->find($this->integer('bank_account_id'));

            if ($account && ($message = BranchAccess::validate($this->user(), (int) $account->branch_id))) {
                $validator->errors()->add('bank_account_id', $message);
            }
        });
    }
}
