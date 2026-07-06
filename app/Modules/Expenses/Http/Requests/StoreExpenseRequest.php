<?php

namespace App\Modules\Expenses\Http\Requests;

use App\Modules\Expenses\Models\Expense;
use App\Support\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('expenses.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'expense_category_id' => ['required', 'integer', 'exists:expense_categories,id'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'spent_at' => ['nullable', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.99'],
            'reference' => ['nullable', 'string', 'max:120'],
            'status' => ['required', Rule::in([Expense::STATUS_REGISTERED, Expense::STATUS_VOID])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($message = BranchAccess::validate($this->user(), $this->integer('branch_id'))) {
                $validator->errors()->add('branch_id', $message);
            }
        });
    }
}
