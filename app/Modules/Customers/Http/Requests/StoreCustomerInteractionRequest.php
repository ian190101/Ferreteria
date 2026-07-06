<?php

namespace App\Modules\Customers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerInteractionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('customers.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['call', 'whatsapp', 'visit', 'email', 'note'])],
            'contact_at' => ['required', 'date'],
            'follow_up_at' => ['nullable', 'date', 'after_or_equal:contact_at'],
            'subject' => ['required', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'status' => ['required', Rule::in(['pending', 'completed'])],
        ];
    }
}
