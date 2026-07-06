<?php

namespace App\Modules\Expenses\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('expenses.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'code' => ['required', 'string', 'max:40', Rule::unique('expense_categories', 'code')->whereNull('deleted_at')->ignore($this->route('category'))],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
