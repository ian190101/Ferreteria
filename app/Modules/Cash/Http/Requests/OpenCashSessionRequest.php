<?php

namespace App\Modules\Cash\Http\Requests;

use App\Modules\Cash\Models\CashRegisterSession;
use Illuminate\Foundation\Http\FormRequest;

class OpenCashSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cash.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'opening_amount' => ['required', 'numeric', 'gte:0', 'max:999999999999.99'],
            'opening_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (
                ! $this->user()?->isSuperAdministrator()
                && ! in_array($this->integer('branch_id'), $this->user()?->accessibleBranchIds() ?? [], true)
            ) {
                $validator->errors()->add('branch_id', 'No tienes acceso a esta sucursal.');

                return;
            }

            $hasOpenSession = CashRegisterSession::query()
                ->where('branch_id', $this->integer('branch_id'))
                ->where('status', CashRegisterSession::STATUS_OPEN)
                ->exists();

            if ($hasOpenSession) {
                $validator->errors()->add('branch_id', 'La sucursal ya tiene una caja abierta.');
            }
        });
    }
}
