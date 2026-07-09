<?php

namespace App\Modules\Sales\Http\Requests;

use App\Modules\Sales\Models\ReceiptTemplate;
use App\Support\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReceiptTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:255'],
            'document_type' => ['required', Rule::in(ReceiptTemplate::DOCUMENT_TYPES)],
            'paper_type' => ['required', Rule::in(ReceiptTemplate::PAPER_TYPES)],
            'thermal_width_mm' => ['nullable', 'integer', 'min:40', 'max:120', 'required_if:paper_type,thermal'],
            'use_branding' => ['required', 'boolean'],
            'is_default' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'layout' => ['required', 'array'],
            'layout.font_family' => ['required', 'string', 'max:40'],
            'layout.font_size' => ['required', 'integer', 'min:8', 'max:18'],
            'layout.margin_mm' => ['required', 'integer', 'min:0', 'max:30'],
            'layout.logo' => ['required', 'array'],
            'layout.logo.path' => ['nullable', 'string', 'max:255'],
            'layout.logo.width_mm' => ['required', 'integer', 'min:8', 'max:80'],
            'layout.logo.position' => ['required', Rule::in(['left', 'center', 'right'])],
            'layout.logo.show' => ['required', 'boolean'],
            'layout.colors.primary' => ['required', 'string', 'max:16'],
            'layout.colors.secondary' => ['required', 'string', 'max:16'],
            'layout.sections' => ['required', 'array', 'min:1'],
            'layout.sections.*.key' => ['required', 'string', 'max:40'],
            'layout.sections.*.label' => ['required', 'string', 'max:80'],
            'layout.sections.*.show' => ['required', 'boolean'],
            'layout.sections.*.order' => ['required', 'integer', 'min:1', 'max:99'],
            'layout.fields' => ['required', 'array'],
            'layout.fields.*' => ['boolean'],
            'layout.item_columns' => ['nullable', 'array'],
            'layout.item_columns.*.key' => ['required_with:layout.item_columns', 'string', 'max:120'],
            'layout.item_columns.*.label' => ['required_with:layout.item_columns', 'string', 'max:80'],
            'layout.item_columns.*.show' => ['required_with:layout.item_columns', 'boolean'],
            'layout.item_columns.*.order' => ['required_with:layout.item_columns', 'integer', 'min:1', 'max:999'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->user()?->isSuperAdministrator()) {
                return;
            }

            if ($message = BranchAccess::validate($this->user(), $this->integer('branch_id'))) {
                $validator->errors()->add('branch_id', $message);
            }
        });
    }
}
