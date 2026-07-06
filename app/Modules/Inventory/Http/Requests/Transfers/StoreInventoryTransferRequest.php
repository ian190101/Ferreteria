<?php

namespace App\Modules\Inventory\Http\Requests\Transfers;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Support\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;

class StoreInventoryTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.transfers.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'from_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'to_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'product_coil_id' => ['nullable', 'integer', 'exists:product_coils,id'],
            'transfer_number' => ['required', 'string', 'max:80', 'unique:inventory_transfers,transfer_number'],
            'meters' => ['required', 'numeric', 'gt:0', 'max:999999999999.999'],
            'transferred_at' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($message = BranchAccess::validate($this->user(), $this->integer('from_branch_id'))) {
                $validator->errors()->add('from_branch_id', $message);

                return;
            }

            if ($message = BranchAccess::validate($this->user(), $this->integer('to_branch_id'))) {
                $validator->errors()->add('to_branch_id', $message);

                return;
            }

            if ($this->integer('from_branch_id') === $this->integer('to_branch_id')) {
                $validator->errors()->add('to_branch_id', 'La sucursal destino debe ser distinta al origen.');
            }

            $product = Product::query()->find($this->integer('product_id'));

            if (! $product) {
                return;
            }

            $meters = round((float) $this->input('meters', 0), 3);

            if ($product->inventory_tracking_mode === Product::TRACKING_COIL) {
                $this->validateCoilTransfer($validator, $product, $meters);

                return;
            }

            $available = (float) ProductBranchStock::query()
                ->where('branch_id', $this->integer('from_branch_id'))
                ->where('product_id', $product->id)
                ->value('available_meters');

            if ($available < $meters) {
                $validator->errors()->add('meters', 'La sucursal origen no tiene stock global suficiente.');
            }
        });
    }

    private function validateCoilTransfer($validator, Product $product, float $meters): void
    {
        if (! $this->filled('product_coil_id')) {
            $validator->errors()->add('product_coil_id', 'La bobina es obligatoria para productos con rastreo individual.');

            return;
        }

        $coil = ProductCoil::query()
            ->where('branch_id', $this->integer('from_branch_id'))
            ->where('product_id', $product->id)
            ->where('status', 'available')
            ->find($this->integer('product_coil_id'));

        if (! $coil) {
            $validator->errors()->add('product_coil_id', 'La bobina no esta disponible en la sucursal origen.');

            return;
        }

        if (abs(((float) $coil->available_meters) - $meters) > 0.001) {
            $validator->errors()->add('meters', 'Las transferencias por bobina deben mover la bobina completa.');
        }
    }
}
