<?php

namespace App\Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Support\BranchAccess;

class StoreSalesSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.manage') ?? false;
    }

    public function rules(): array
    {
        $nameRules = ['required', 'string', 'max:255'];
        $codeRules = ['required_if:kind,currency', 'nullable', 'string', 'max:8'];
        $percentageRules = ['required_if:kind,advance_option', 'nullable', 'numeric', 'min:0', 'max:100'];
        $documentTypeRules = ['required_if:kind,document_sequence', 'nullable', Rule::in(['quotation', 'sale_note'])];

        if ($this->input('kind') === 'sale_type') {
            $nameRules[] = Rule::unique('sale_types', 'name');
        }

        if ($this->input('kind') === 'currency') {
            $codeRules[] = Rule::unique('currencies', 'code');
        }

        if ($this->input('kind') === 'advance_option') {
            $percentageRules[] = Rule::unique('advance_options', 'percentage');
        }

        return [
            'kind' => ['required', Rule::in(['sale_type', 'currency', 'advance_option', 'document_sequence'])],
            'branch_id' => ['required_if:kind,document_sequence', 'nullable', 'integer', 'exists:branches,id'],
            'document_type' => $documentTypeRules,
            'name' => $nameRules,
            'code' => $codeRules,
            'symbol' => ['required_if:kind,currency', 'nullable', 'string', 'max:8'],
            'exchange_rate_to_bob' => ['required_if:kind,currency', 'nullable', 'numeric', 'gt:0', 'max:999999999999.999999'],
            'is_base' => ['nullable', 'boolean'],
            'percentage' => $percentageRules,
            'prefix' => ['required_if:kind,document_sequence', 'nullable', 'string', 'max:24'],
            'next_number' => ['required_if:kind,document_sequence', 'nullable', 'integer', 'min:1', 'max:999999999999'],
            'padding' => ['required_if:kind,document_sequence', 'nullable', 'integer', 'min:1', 'max:12'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('kind') !== 'document_sequence') {
                return;
            }

            if ($message = BranchAccess::validate($this->user(), $this->integer('branch_id'))) {
                $validator->errors()->add('branch_id', $message);

                return;
            }

            $exists = \App\Modules\Sales\Models\DocumentSequence::query()
                ->where('branch_id', $this->integer('branch_id'))
                ->where('document_type', $this->input('document_type'))
                ->exists();

            if ($exists) {
                $validator->errors()->add('document_type', 'Ya existe una secuencia para esta sucursal y tipo de documento.');
            }
        });
    }
}
