<?php

namespace App\Modules\Expenses\Http\Requests;

use App\Modules\Expenses\Models\Expense;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class VoidExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('expenses.manage') ?? false;
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
                $expense = $this->route('expense');

                if ($expense instanceof Expense && $expense->status === Expense::STATUS_VOID) {
                    $validator->errors()->add('expense', 'El gasto ya se encuentra anulado.');
                }
            },
        ];
    }
}
