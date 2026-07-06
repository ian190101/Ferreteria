<?php

namespace App\Modules\Inventory\Http\Requests\Reservations;

use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Sales\Models\Sale;
use App\Support\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;

class StoreInventoryReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.reservations.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'product_coil_id' => ['nullable', 'integer', 'exists:product_coils,id'],
            'sale_id' => ['nullable', 'integer', 'exists:sales,id'],
            'meters' => ['required', 'numeric', 'gt:0', 'max:999999999999.999'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'reason' => ['nullable', 'string', 'max:255'],
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

            $meters = round((float) $this->input('meters'), 3);

            if ($this->filled('sale_id')) {
                $sale = Sale::query()
                    ->where('branch_id', $this->integer('branch_id'))
                    ->where('document_type', 'quotation')
                    ->where('status', 'quoted')
                    ->find($this->integer('sale_id'));

                if (! $sale) {
                    $validator->errors()->add('sale_id', 'La cotizacion debe estar vigente y pertenecer a la sucursal seleccionada.');
                }
            }

            if ($product->inventory_tracking_mode === Product::TRACKING_COIL) {
                $coil = ProductCoil::query()
                    ->where('branch_id', $this->integer('branch_id'))
                    ->where('product_id', $product->id)
                    ->where('status', 'available')
                    ->find($this->integer('product_coil_id'));

                if (! $coil) {
                    $validator->errors()->add('product_coil_id', 'La bobina es obligatoria y debe estar disponible en la sucursal.');

                    return;
                }

                $reserved = (float) InventoryReservation::query()
                    ->where('product_coil_id', $coil->id)
                    ->where('status', InventoryReservation::STATUS_ACTIVE)
                    ->sum('meters');

                if (((float) $coil->available_meters - $reserved) < $meters) {
                    $validator->errors()->add('meters', 'La bobina no tiene metraje libre suficiente para reservar.');
                }

                return;
            }

            if ($this->filled('product_coil_id')) {
                $validator->errors()->add('product_coil_id', 'Los productos con rastreo global no deben seleccionar bobina.');
            }

            $stock = ProductBranchStock::query()
                ->where('branch_id', $this->integer('branch_id'))
                ->where('product_id', $product->id)
                ->first();

            $freeMeters = $stock ? (float) $stock->available_meters - (float) $stock->reserved_meters : 0;

            if ($freeMeters < $meters) {
                $validator->errors()->add('meters', 'El stock global libre no alcanza para reservar ese metraje.');
            }
        });
    }
}
