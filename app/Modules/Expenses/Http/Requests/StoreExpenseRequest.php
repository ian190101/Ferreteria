<?php

namespace App\Modules\Expenses\Http\Requests;

use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\HumanResources\Models\Worker;
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
            'worker_id' => ['nullable', 'integer', Rule::exists('workers', 'id')->whereNull('deleted_at')],
            'period_from' => ['nullable', 'date'],
            'period_to' => ['nullable', 'date', 'after_or_equal:period_from'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($message = BranchAccess::validate($this->user(), $this->integer('branch_id'))) {
                $validator->errors()->add('branch_id', $message);
            }

            $category = ExpenseCategory::query()
                ->whereKey($this->integer('expense_category_id'))
                ->first(['id', 'code']);

            if ($category?->code !== ExpenseCategory::SALARY_PAYROLL_CODE) {
                return;
            }

            if (! $this->user()?->can('payroll.manage')) {
                $validator->errors()->add('expense_category_id', 'No tienes permiso para registrar pago de sueldos.');
            }

            if (! $this->filled('worker_id')) {
                $validator->errors()->add('worker_id', 'Selecciona el trabajador al que se pagara el sueldo.');

                return;
            }

            $worker = Worker::query()->find($this->integer('worker_id'));

            if (! $worker) {
                return;
            }

            if (! BranchAccess::canAccess($this->user(), (int) $worker->branch_id)) {
                $validator->errors()->add('worker_id', 'No puedes pagar sueldos de trabajadores de otra sucursal.');
            }

            if ((int) $worker->branch_id !== $this->integer('branch_id')) {
                $validator->errors()->add('worker_id', 'El trabajador seleccionado no pertenece a la sucursal del egreso.');
            }
        });
    }
}
