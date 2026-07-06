<?php

namespace App\Modules\Inventory\Http\Requests\Adjustments;

use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Support\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.adjustments.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'product_coil_id' => ['nullable', 'integer', 'exists:product_coils,id'],
            'adjustment_number' => ['required', 'string', 'max:80', 'unique:inventory_adjustments,adjustment_number'],
            'type' => ['required', Rule::in([InventoryAdjustment::TYPE_INCREASE, InventoryAdjustment::TYPE_DECREASE])],
            'meters' => ['required', 'numeric', 'gt:0', 'max:999999999999.999'],
            'reason' => ['required', 'string', 'max:255'],
            'adjusted_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
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

            if (! $product) {
                return;
            }

            $meters = (float) $this->input('meters', 0);
            $isDecrease = $this->input('type') === InventoryAdjustment::TYPE_DECREASE;

            if ($product->inventory_tracking_mode === Product::TRACKING_COIL) {
                if (! $this->filled('product_coil_id')) {
                    $validator->errors()->add('product_coil_id', 'La bobina es obligatoria para productos con rastreo individual.');

                    return;
                }

                $coil = ProductCoil::query()
                    ->where('branch_id', $this->integer('branch_id'))
                    ->where('product_id', $product->id)
                    ->find($this->integer('product_coil_id'));

                if (! $coil) {
                    $validator->errors()->add('product_coil_id', 'La bobina no corresponde a la sucursal o producto.');

                    return;
                }

                if ($isDecrease && (float) $coil->available_meters < $meters) {
                    $validator->errors()->add('meters', 'El ajuste no puede dejar la bobina con saldo negativo.');
                }

                return;
            }

            $available = (float) ProductBranchStock::query()
                ->where('branch_id', $this->integer('branch_id'))
                ->where('product_id', $product->id)
                ->value('available_meters');

            if ($isDecrease && $available < $meters) {
                $validator->errors()->add('meters', 'El ajuste no puede dejar el stock global en negativo.');
            }
        });
    }
}
