<?php

namespace App\Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Support\BranchAccess;
use App\Modules\Sales\Models\AdvanceOption;

class UpdateSalesSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.manage') ?? false;
    }

    public function rules(): array
    {
        $kind = $this->route('kind');
        $settingId = (int) $this->route('setting');
        $nameRules = ['required', 'string', 'max:255'];
        $codeRules = ['required_if:kind,currency', 'nullable', 'string', 'max:8'];
        $advanceTypeRules = ['required_if:kind,advance_option', 'nullable', Rule::in([AdvanceOption::TYPE_PERCENTAGE, AdvanceOption::TYPE_AMOUNT])];
        $percentageRules = [Rule::requiredIf(fn () => $kind === 'advance_option' && $this->input('type') === AdvanceOption::TYPE_PERCENTAGE), 'nullable', 'numeric', 'min:0', 'max:100'];
        $amountRules = [Rule::requiredIf(fn () => $kind === 'advance_option' && $this->input('type') === AdvanceOption::TYPE_AMOUNT), 'nullable', 'numeric', 'min:0', 'max:999999999999.99'];
        $documentTypeRules = ['required_if:kind,document_sequence', 'nullable', Rule::in(['quotation', 'sale_note'])];

        if ($kind === 'sale_type') {
            $nameRules[] = Rule::unique('sale_types', 'name')->ignore($settingId);
        }

        if ($kind === 'currency') {
            $codeRules[] = Rule::unique('currencies', 'code')->ignore($settingId);
        }

        if ($kind === 'advance_option' && $this->input('type') === AdvanceOption::TYPE_PERCENTAGE) {
            $percentageRules[] = Rule::unique('advance_options', 'percentage')->ignore($settingId);
        }

        return [
            'name' => $nameRules,
            'branch_id' => ['required_if:kind,document_sequence', 'nullable', 'integer', 'exists:branches,id'],
            'document_type' => $documentTypeRules,
            'code' => $codeRules,
            'symbol' => ['required_if:kind,currency', 'nullable', 'string', 'max:8'],
            'exchange_rate_to_bob' => ['required_if:kind,currency', 'nullable', 'numeric', 'gt:0', 'max:999999999999.999999'],
            'is_base' => ['nullable', 'boolean'],
            'type' => $advanceTypeRules,
            'percentage' => $percentageRules,
            'amount' => $amountRules,
            'prefix' => ['required_if:kind,document_sequence', 'nullable', 'string', 'max:24'],
            'next_number' => ['required_if:kind,document_sequence', 'nullable', 'integer', 'min:1', 'max:999999999999'],
            'padding' => ['required_if:kind,document_sequence', 'nullable', 'integer', 'min:1', 'max:12'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->route('kind') !== 'document_sequence') {
                return;
            }

            if ($message = BranchAccess::validate($this->user(), $this->integer('branch_id'))) {
                $validator->errors()->add('branch_id', $message);

                return;
            }

            $exists = \App\Modules\Sales\Models\DocumentSequence::query()
                ->where('branch_id', $this->integer('branch_id'))
                ->where('document_type', $this->input('document_type'))
                ->whereKeyNot((int) $this->route('setting'))
                ->exists();

            if ($exists) {
                $validator->errors()->add('document_type', 'Ya existe una secuencia para esta sucursal y tipo de documento.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $payload = ['kind' => $this->route('kind')];

        if ($this->route('kind') === 'advance_option') {
            $payload['type'] = $this->input('type', AdvanceOption::TYPE_PERCENTAGE);
        }

        $this->merge($payload);
    }
}
