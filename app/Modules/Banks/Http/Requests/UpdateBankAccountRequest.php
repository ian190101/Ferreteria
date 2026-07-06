<?php

namespace App\Modules\Banks\Http\Requests;

use App\Support\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('banks.manage') ?? false;
    }

    public function rules(): array
    {
        $account = $this->route('account');

        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:120'],
            'bank_name' => ['required', 'string', 'max:120'],
            'account_number' => [
                'required',
                'string',
                'max:80',
                Rule::unique('bank_accounts', 'account_number')
                    ->where('branch_id', $this->integer('branch_id'))
                    ->whereNull('deleted_at')
                    ->ignore($account?->id),
            ],
            'currency_code' => ['required', 'string', 'max:10'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $account = $this->route('account');

            if ($account && ($message = BranchAccess::validate($this->user(), (int) $account->branch_id))) {
                $validator->errors()->add('branch_id', $message);

                return;
            }

            if ($message = BranchAccess::validate($this->user(), $this->integer('branch_id'))) {
                $validator->errors()->add('branch_id', $message);
            }
        });
    }
}
