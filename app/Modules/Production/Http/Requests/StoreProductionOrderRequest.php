<?php

namespace App\Modules\Production\Http\Requests;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Support\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductionOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('production.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'order_number' => ['required', 'string', 'max:80', 'unique:production_orders,order_number'],
            'produced_at' => ['nullable', 'date'],
            'input_product_id' => ['required', 'integer', 'exists:products,id'],
            'input_product_coil_id' => ['nullable', 'integer', 'exists:product_coils,id'],
            'output_product_id' => ['required', 'integer', 'exists:products,id'],
            'input_meters' => ['required', 'numeric', 'gt:0', 'max:999999999999.999'],
            'output_meters' => ['required', 'numeric', 'gt:0', 'max:999999999999.999'],
            'waste_meters' => ['nullable', 'numeric', 'gte:0', 'max:999999999999.999'],
            'output_coil_barcode' => ['nullable', 'string', 'max:80', 'unique:product_coils,barcode'],
            'output_lot_number' => ['nullable', 'string', 'max:80'],
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

            $inputProduct = Product::query()->find($this->integer('input_product_id'));
            $outputProduct = Product::query()->find($this->integer('output_product_id'));

            if (! $inputProduct || ! $outputProduct) {
                return;
            }

            $inputMeters = (float) $this->input('input_meters', 0);

            if ($inputProduct->inventory_tracking_mode === Product::TRACKING_COIL) {
                if (! $this->filled('input_product_coil_id')) {
                    $validator->errors()->add('input_product_coil_id', 'La bobina de entrada es obligatoria para este producto.');

                    return;
                }

                $coil = ProductCoil::query()
                    ->where('branch_id', $this->integer('branch_id'))
                    ->where('product_id', $inputProduct->id)
                    ->where('status', 'available')
                    ->find($this->integer('input_product_coil_id'));

                if (! $coil || (float) $coil->available_meters < $inputMeters) {
                    $validator->errors()->add('input_meters', 'La bobina de entrada no tiene metros suficientes.');
                }
            } else {
                $available = (float) ProductBranchStock::query()
                    ->where('branch_id', $this->integer('branch_id'))
                    ->where('product_id', $inputProduct->id)
                    ->value('available_meters');

                if ($available < $inputMeters) {
                    $validator->errors()->add('input_meters', 'El stock global de entrada no tiene metros suficientes.');
                }
            }

            if ($outputProduct->inventory_tracking_mode === Product::TRACKING_COIL) {
                if (! $this->filled('output_coil_barcode')) {
                    $validator->errors()->add('output_coil_barcode', 'El barcode de salida es obligatorio para productos por bobina.');
                }

                if (! $this->filled('output_lot_number')) {
                    $validator->errors()->add('output_lot_number', 'El lote de salida es obligatorio para productos por bobina.');
                }
            }
        });
    }
}
