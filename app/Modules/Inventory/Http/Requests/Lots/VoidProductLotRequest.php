<?php

namespace App\Modules\Inventory\Http\Requests\Lots;

use App\Modules\Inventory\Services\LotAuthorization;
use Illuminate\Foundation\Http\FormRequest;

class VoidProductLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lot = $this->route('lot');

        return $lot && app(LotAuthorization::class)->canManage($this->user(), $lot);
    }

    public function rules(): array
    {
        return ['void_reason' => ['required', 'string', 'max:10000']];
    }
}
