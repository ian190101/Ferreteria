<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\Product;
use App\Support\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductCoilRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.coils.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'barcode' => ['required', 'string', 'max:80', 'unique:product_coils,barcode'],
            'lot_number' => ['required', 'string', 'max:80'],
            'initial_kg' => ['nullable', 'numeric', 'gt:0', 'max:999999999999.999'],
            'initial_meters' => ['required', 'numeric', 'gt:0', 'max:999999999999.999'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($message = BranchAccess::validate($this->user(), $this->integer('branch_id'))) {
                $validator->errors()->add('branch_id', $message);

                return;
            }

            $product = Product::query()->find($this->integer('product_id'));

            if ($product && $product->inventory_tracking_mode !== Product::TRACKING_COIL) {
                $validator->errors()->add('product_id', 'Solo los productos con rastreo individual admiten lotes o unidades fisicas independientes.');
            }
        });
    }
}
