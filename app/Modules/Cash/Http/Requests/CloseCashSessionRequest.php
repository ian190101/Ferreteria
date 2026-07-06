<?php

namespace App\Modules\Cash\Http\Requests;

use App\Modules\Cash\Models\CashRegisterSession;
use Illuminate\Foundation\Http\FormRequest;

class CloseCashSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cash.manage') ?? false;
    }

    public function rules(): array
    {
        $cashCountRules = collect(array_keys(CashRegisterSession::CASH_DENOMINATIONS))
            ->mapWithKeys(fn (string $key) => ["cash_count.{$key}" => ['required', 'integer', 'min:0', 'max:999999']])
            ->all();

        return [
            'cash_count' => ['required', 'array'],
            ...$cashCountRules,
            'closing_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            /** @var CashRegisterSession|null $session */
            $session = $this->route('cashSession');

            if (! $session) {
                return;
            }

            if ($session->status !== CashRegisterSession::STATUS_OPEN) {
                $validator->errors()->add('closed_at', 'La caja ya fue cerrada.');
            }

            if (
                ! $this->user()?->isSuperAdministrator()
                && (
                    (int) $session->opened_by !== (int) $this->user()?->id
                    || ! in_array((int) $session->branch_id, $this->user()?->accessibleBranchIds() ?? [], true)
                )
            ) {
                $validator->errors()->add('closed_at', 'Solo puedes cerrar cajas abiertas por tu usuario.');
            }

        });
    }
}
