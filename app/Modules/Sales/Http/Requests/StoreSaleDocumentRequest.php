<?php

namespace App\Modules\Sales\Http\Requests;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Support\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaleDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sales.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', Rule::in(['quotation', 'sale_note'])],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'sale_type_id' => ['required', 'integer', 'exists:sale_types,id'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'advance_option_id' => ['nullable', 'integer', 'exists:advance_options,id'],
            'receipt_number' => ['nullable', 'string', 'max:80', 'unique:sales,receipt_number'],
            'customer_name' => ['required_without:customer_id', 'nullable', 'string', 'max:255'],
            'customer_document' => ['nullable', 'string', 'max:80'],
            'customer_contact' => ['nullable', 'string', 'max:40'],
            'sold_at' => ['nullable', 'date'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.product_coil_id' => ['nullable', 'integer', 'exists:product_coils,id'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.unit_label' => ['required', 'string', 'max:16'],
            'items.*.display_quantity' => ['nullable', 'numeric', 'gt:0', 'max:999999999999.999'],
            'items.*.display_unit_label' => ['nullable', 'string', 'max:24'],
            'items.*.item_attributes' => ['nullable', 'array'],
            'items.*.calculation_mode' => ['nullable', Rule::in(['direct', 'length', 'weight'])],
            'items.*.item_attributes.*.code' => ['required_with:items.*.item_attributes', 'string', 'max:80'],
            'items.*.item_attributes.*.name' => ['required_with:items.*.item_attributes', 'string', 'max:120'],
            'items.*.item_attributes.*.value' => ['nullable', 'string', 'max:120'],
            'items.*.item_attributes.*.unit' => ['nullable', 'string', 'max:24'],
            'items.*.meters' => ['required', 'numeric', 'gt:0', 'max:999999999999.999'],
            'items.*.unit_price' => ['required', 'numeric', 'gte:0', 'max:999999999999.9999'],
            'items.*.discount_amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($message = BranchAccess::validate($this->user(), $this->integer('branch_id'))) {
                $validator->errors()->add('branch_id', $message);

                return;
            }

            if ($this->input('document_type') !== 'sale_note') {
                return;
            }

            $items = collect($this->input('items', []));
            $globalMetersByProduct = [];
            $coilMetersById = [];

            foreach ($items as $index => $item) {
                $product = Product::query()->find($item['product_id'] ?? null);

                if (! $product) {
                    continue;
                }

                $meters = round((float) ($item['meters'] ?? 0), 3);

                if ($product->inventory_tracking_mode === Product::TRACKING_COIL) {
                    $coilId = $item['product_coil_id'] ?? null;

                    if (! $coilId) {
                        $validator->errors()->add("items.{$index}.product_coil_id", 'La bobina es obligatoria para productos con rastreo individual.');

                        continue;
                    }

                    $coilMetersById[$coilId] = ($coilMetersById[$coilId] ?? 0) + $meters;

                    continue;
                }

                if (filled($item['product_coil_id'] ?? null)) {
                    $validator->errors()->add("items.{$index}.product_coil_id", 'Los productos con rastreo global no deben seleccionar bobina.');
                }

                $globalMetersByProduct[$product->id] = ($globalMetersByProduct[$product->id] ?? 0) + $meters;
            }

            foreach ($globalMetersByProduct as $productId => $meters) {
                $stock = ProductBranchStock::query()
                    ->where('branch_id', $this->integer('branch_id'))
                    ->where('product_id', $productId)
                    ->first();
                $available = $stock ? (float) $stock->available_meters - (float) $stock->reserved_meters : 0;

                if ($available < $meters) {
                    $validator->errors()->add('items', 'La sucursal no tiene stock global libre suficiente para uno o mas productos.');
                }
            }

            foreach ($coilMetersById as $coilId => $meters) {
                $coil = ProductCoil::query()
                    ->where('branch_id', $this->integer('branch_id'))
                    ->where('status', 'available')
                    ->find($coilId);

                if (! $coil) {
                    $validator->errors()->add('items', 'Una bobina seleccionada no esta disponible en la sucursal.');

                    continue;
                }

                $reserved = (float) InventoryReservation::query()
                    ->where('product_coil_id', $coil->id)
                    ->where('status', InventoryReservation::STATUS_ACTIVE)
                    ->sum('meters');

                if (((float) $coil->available_meters - $reserved) < $meters) {
                    $validator->errors()->add('items', 'Una bobina seleccionada no tiene metraje suficiente.');
                }
            }
        });
    }
}
