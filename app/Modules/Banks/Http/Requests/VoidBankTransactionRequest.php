<?php

namespace App\Modules\Banks\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VoidBankTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('banks.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ];
    }
}
