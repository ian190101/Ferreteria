<?php

namespace App\Modules\Production\Http\Requests;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Production\Models\ProductionFormula;
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
            'production_formula_id' => ['nullable', 'integer', 'exists:production_formulas,id'],
            'input_product_id' => ['nullable', 'required_without:production_formula_id', 'integer', 'exists:products,id'],
            'input_product_coil_id' => ['nullable', 'integer', 'exists:product_coils,id'],
            'output_product_id' => ['nullable', 'required_without:production_formula_id', 'integer', 'exists:products,id'],
            'input_meters' => ['nullable', 'required_without:production_formula_id', 'numeric', 'gt:0', 'max:999999999999.999'],
            'output_meters' => ['required', 'numeric', 'gt:0', 'max:999999999999.999'],
            'waste_meters' => ['nullable', 'numeric', 'gte:0', 'max:999999999999.999'],
            'labor_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999999.999'],
            'overhead_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999999.999'],
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

            $formula = $this->filled('production_formula_id')
                ? ProductionFormula::query()->with('items.inputProduct', 'outputProduct')->find($this->integer('production_formula_id'))
                : null;
            $inputProduct = $formula?->items->first()?->inputProduct ?? Product::query()->find($this->integer('input_product_id'));
            $outputProduct = $formula?->outputProduct ?? Product::query()->find($this->integer('output_product_id'));

            if (! $inputProduct || ! $outputProduct) {
                return;
            }

            if ($formula && $formula->items->isEmpty()) {
                $validator->errors()->add('production_formula_id', 'La formula seleccionada no tiene insumos configurados.');

                return;
            }

            if ($formula && $formula->branch_id && (int) $formula->branch_id !== $this->integer('branch_id')) {
                $validator->errors()->add('production_formula_id', 'La formula seleccionada pertenece a otra sucursal.');

                return;
            }

            $inputMeters = $formula
                ? (float) $formula->items->first()->quantity * ((float) $this->input('output_meters', 0) / max((float) $formula->yield_quantity, 0.0001))
                : (float) $this->input('input_meters', 0);

            if (! $formula && $inputProduct->inventory_tracking_mode === Product::TRACKING_COIL) {
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
            } elseif ($formula) {
                foreach ($formula->items as $item) {
                    if ($item->inputProduct->inventory_tracking_mode === Product::TRACKING_COIL) {
                        $validator->errors()->add('production_formula_id', "El insumo {$item->inputProduct->name} usa bobinas. Para ese caso registra la produccion simple seleccionando la bobina exacta.");

                        continue;
                    }

                    $required = round((float) $item->quantity * ((float) $this->input('output_meters', 0) / max((float) $formula->yield_quantity, 0.0001)), 4);
                    $available = (float) ProductBranchStock::query()
                        ->where('branch_id', $this->integer('branch_id'))
                        ->where('product_id', $item->input_product_id)
                        ->value('available_meters');

                    if ($available < $required) {
                        $validator->errors()->add('production_formula_id', "Stock insuficiente para el insumo {$item->inputProduct->name}.");
                    }
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
