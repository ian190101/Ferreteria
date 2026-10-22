<?php

namespace App\Modules\SystemSuperadmin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GitCommitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('sistemasuperadmin') ?? false;
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:3', 'max:500'],
            'push' => ['nullable', 'boolean'],
            'files' => ['sometimes', 'array', 'min:1', 'max:500'],
            'files.*' => ['required', 'string', 'max:1000', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'message.required' => 'Debes escribir un mensaje descriptivo para el commit.',
            'message.min' => 'El mensaje de commit debe tener al menos 3 caracteres.',
            'message.max' => 'El mensaje de commit no puede superar los 500 caracteres.',
        ];
    }
}
