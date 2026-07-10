<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Http\Requests\StoreSalesSettingRequest;
use App\Modules\Sales\Http\Requests\UpdateSalesSettingRequest;
use App\Modules\Sales\Models\AdvanceOption;
use App\Modules\Sales\Models\Currency;
use App\Modules\Sales\Models\DocumentSequence;
use App\Modules\Sales\Models\SaleType;
use App\Modules\Settings\Models\SystemSetting;
use App\Support\BranchAccess;
use App\Support\DecimalPrecision;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SalesSettingController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Sales/Settings', [
            'saleTypes' => SaleType::query()->orderBy('name')->paginate(15, ['*'], 'sale_types_page'),
            'currencies' => Currency::query()->orderByDesc('is_base')->orderBy('name')->paginate(15, ['*'], 'currencies_page'),
            'advanceOptions' => AdvanceOption::query()->orderBy('type')->orderBy('percentage')->orderBy('amount')->paginate(15, ['*'], 'advance_options_page'),
            'documentSequences' => DocumentSequence::query()
                ->with('branch:id,name')
                ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
                ->orderBy('branch_id')
                ->orderBy('document_type')
                ->paginate(15, ['*'], 'document_sequences_page'),
            'branches' => UiCatalogCache::activeBranchesForUser($request->user()),
            'decimalPrecision' => DecimalPrecision::config(),
        ]);
    }

    public function store(StoreSalesSettingRequest $request): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(fn () => match ($data['kind']) {
            'sale_type' => $this->storeSaleType($data),
            'currency' => $this->storeCurrency($data),
            'advance_option' => $this->storeAdvanceOption($data),
            'document_sequence' => $this->storeDocumentSequence($data),
        });
        UiCatalogCache::forgetSalesCatalogs();

        return redirect()->route('sales.settings.index')->with('success', 'Configuracion creada correctamente.');
    }

    public function update(UpdateSalesSettingRequest $request, string $kind, int $setting): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(fn () => match ($kind) {
            'sale_type' => $this->updateSaleType($setting, $data),
            'currency' => $this->updateCurrency($setting, $data),
            'advance_option' => $this->updateAdvanceOption($setting, $data),
            'document_sequence' => $this->updateDocumentSequence($setting, $data),
            default => throw ValidationException::withMessages(['kind' => 'Catalogo no valido.']),
        });
        UiCatalogCache::forgetSalesCatalogs();

        return redirect()->route('sales.settings.index')->with('success', 'Configuracion actualizada correctamente.');
    }

    public function destroy(string $kind, int $setting): RedirectResponse
    {
        DB::transaction(fn () => match ($kind) {
            'sale_type' => SaleType::query()->findOrFail($setting)->delete(),
            'currency' => $this->deleteCurrency($setting),
            'advance_option' => AdvanceOption::query()->findOrFail($setting)->delete(),
            'document_sequence' => $this->deleteDocumentSequence($setting),
            default => throw ValidationException::withMessages(['kind' => 'Catalogo no valido.']),
        });
        UiCatalogCache::forgetSalesCatalogs();

        return redirect()->route('sales.settings.index')->with('success', 'Configuracion eliminada correctamente.');
    }

    public function updateDecimals(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'decimal_precision' => ['required', 'array'],
        ]);

        SystemSetting::query()
            ->where('key', DecimalPrecision::SETTING_KEY)
            ->update(['value' => DecimalPrecision::normalize($validated['decimal_precision'])]);
        DecimalPrecision::forget();

        return redirect()->route('sales.settings.index')->with('success', 'Decimales actualizados correctamente.');
    }

    private function storeSaleType(array $data): void
    {
        SaleType::query()->create([
            'name' => $data['name'],
            'is_active' => $data['is_active'],
        ]);
    }

    private function storeCurrency(array $data): void
    {
        $isBase = (bool) ($data['is_base'] ?? false);

        if ($isBase) {
            Currency::query()->update(['is_base' => false]);
        }

        Currency::query()->create([
            'name' => $data['name'],
            'code' => strtoupper($data['code']),
            'symbol' => $data['symbol'],
            'exchange_rate_to_bob' => $isBase ? 1 : $data['exchange_rate_to_bob'],
            'is_base' => $isBase,
            'is_active' => $isBase ? true : $data['is_active'],
        ]);
    }

    private function storeAdvanceOption(array $data): void
    {
        $isPercentage = $data['type'] === AdvanceOption::TYPE_PERCENTAGE;

        AdvanceOption::query()->create([
            'name' => $data['name'],
            'type' => $data['type'],
            'percentage' => $isPercentage ? $data['percentage'] : null,
            'amount' => $isPercentage ? null : $data['amount'],
            'is_active' => $data['is_active'],
        ]);
    }

    private function storeDocumentSequence(array $data): void
    {
        abort_unless(BranchAccess::canAccess(request()->user(), (int) $data['branch_id']), 403);

        DocumentSequence::query()->create([
            'branch_id' => $data['branch_id'],
            'document_type' => $data['document_type'],
            'name' => $data['name'],
            'prefix' => $data['prefix'],
            'next_number' => $data['next_number'],
            'padding' => $data['padding'],
            'is_active' => $data['is_active'],
        ]);
    }

    private function updateSaleType(int $setting, array $data): void
    {
        SaleType::query()->findOrFail($setting)->update([
            'name' => $data['name'],
            'is_active' => $data['is_active'],
        ]);
    }

    private function updateCurrency(int $setting, array $data): void
    {
        $currency = Currency::query()->findOrFail($setting);
        $isBase = (bool) ($data['is_base'] ?? false);

        if ($currency->is_base && ! $isBase) {
            throw ValidationException::withMessages([
                'is_base' => 'Debe existir una moneda base. Marca otra moneda como base antes de quitar esta.',
            ]);
        }

        if ($isBase) {
            Currency::query()->whereKeyNot($currency->id)->update(['is_base' => false]);
        }

        $currency->update([
            'name' => $data['name'],
            'code' => strtoupper($data['code']),
            'symbol' => $data['symbol'],
            'exchange_rate_to_bob' => $isBase ? 1 : $data['exchange_rate_to_bob'],
            'is_base' => $isBase,
            'is_active' => $isBase ? true : $data['is_active'],
        ]);
    }

    private function updateAdvanceOption(int $setting, array $data): void
    {
        $isPercentage = $data['type'] === AdvanceOption::TYPE_PERCENTAGE;

        AdvanceOption::query()->findOrFail($setting)->update([
            'name' => $data['name'],
            'type' => $data['type'],
            'percentage' => $isPercentage ? $data['percentage'] : null,
            'amount' => $isPercentage ? null : $data['amount'],
            'is_active' => $data['is_active'],
        ]);
    }

    private function updateDocumentSequence(int $setting, array $data): void
    {
        $sequence = DocumentSequence::query()->findOrFail($setting);

        abort_unless(
            BranchAccess::canAccess(request()->user(), $sequence->branch_id)
                && BranchAccess::canAccess(request()->user(), (int) $data['branch_id']),
            403
        );

        $sequence->update([
            'branch_id' => $data['branch_id'],
            'document_type' => $data['document_type'],
            'name' => $data['name'],
            'prefix' => $data['prefix'],
            'next_number' => $data['next_number'],
            'padding' => $data['padding'],
            'is_active' => $data['is_active'],
        ]);
    }

    private function deleteCurrency(int $setting): void
    {
        $currency = Currency::query()->findOrFail($setting);

        if ($currency->is_base) {
            throw ValidationException::withMessages([
                'currency' => 'No se puede eliminar la moneda base.',
            ]);
        }

        $currency->delete();
    }

    private function deleteDocumentSequence(int $setting): void
    {
        $sequence = DocumentSequence::query()->findOrFail($setting);

        abort_unless(BranchAccess::canAccess(request()->user(), (int) $sequence->branch_id), 403);

        $sequence->delete();
    }
}
