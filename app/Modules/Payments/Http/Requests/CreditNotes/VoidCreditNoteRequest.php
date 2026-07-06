<?php

namespace App\Modules\Payments\Http\Requests\CreditNotes;

use Illuminate\Foundation\Http\FormRequest;

class VoidCreditNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('credit-notes.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
