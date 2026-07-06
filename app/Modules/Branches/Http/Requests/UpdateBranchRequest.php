<?php

namespace App\Modules\Branches\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('branches.manage') ?? false;
    }

    public function rules(): array
    {
        $branchId = $this->route('branch')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32', 'alpha_dash:ascii', Rule::unique('branches', 'code')->ignore($branchId)],
            'barcode' => ['required', 'string', 'max:80', Rule::unique('branches', 'barcode')->ignore($branchId)],
            'phone' => ['nullable', 'string', 'max:40'],
            'secondary_phone' => ['nullable', 'string', 'max:40'],
            'point_of_sale_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'setting.primary_color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'setting.secondary_color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'setting.logo_path' => ['nullable', 'string', 'max:255'],
            'setting.theme_mode' => ['required', Rule::in(['light', 'dark', 'system'])],
        ];
    }
}
