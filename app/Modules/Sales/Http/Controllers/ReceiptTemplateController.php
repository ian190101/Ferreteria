<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Http\Requests\StoreReceiptTemplateRequest;
use App\Modules\Sales\Http\Requests\UpdateReceiptTemplateRequest;
use App\Modules\Sales\Models\ReceiptTemplate;
use App\Modules\Sales\Services\SalesDocumentPolicy;
use App\Support\BranchAccess;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ReceiptTemplateController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Sales/Templates/Index', [
            'templates' => ReceiptTemplate::query()
                ->with('branch:id,name')
                ->when(! $request->user()->isSuperAdministrator(), fn ($query) => $query->whereIn('branch_id', $request->user()->accessibleBranchIds() ?: [-1]))
                ->latest('id')
                ->paginate(15),
            'documentPolicy' => app(SalesDocumentPolicy::class)->summary(),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Sales/Templates/Form', [
            'template' => null,
            'branches' => $this->branches($request),
            'defaultLayout' => $this->layoutWithCatalogFields(ReceiptTemplate::defaultLayout()),
            'attributeFields' => $this->attributeFields(),
            'documentPolicy' => app(SalesDocumentPolicy::class)->summary(),
        ]);
    }

    public function store(StoreReceiptTemplateRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $data = $request->validated();

            abort_unless($this->canUseBranch($request, $data['branch_id'] ?? null), 403);

            if ($data['is_default']) {
                ReceiptTemplate::query()
                    ->where('document_type', $data['document_type'])
                    ->where('branch_id', $data['branch_id'])
                    ->update(['is_default' => false]);
            }

            ReceiptTemplate::query()->create($data);
        });

        $this->bumpTemplateCacheVersion();

        return redirect()->route('sales.templates.index')->with('success', 'Plantilla creada correctamente.');
    }

    public function edit(Request $request, ReceiptTemplate $template): Response
    {
        abort_unless($this->canManageTemplate($request, $template), 403);

        return Inertia::render('Sales/Templates/Form', [
            'template' => [
                ...$template->toArray(),
                'layout' => $this->layoutWithCatalogFields($template->layout ?? []),
            ],
            'branches' => $this->branches($request),
            'defaultLayout' => $this->layoutWithCatalogFields(ReceiptTemplate::defaultLayout()),
            'attributeFields' => $this->attributeFields(),
            'documentPolicy' => app(SalesDocumentPolicy::class)->summary(),
        ]);
    }

    public function update(UpdateReceiptTemplateRequest $request, ReceiptTemplate $template): RedirectResponse
    {
        DB::transaction(function () use ($request, $template) {
            $data = $request->validated();

            abort_unless($this->canManageTemplate($request, $template), 403);
            abort_unless($this->canUseBranch($request, $data['branch_id'] ?? null), 403);

            if ($data['is_default']) {
                ReceiptTemplate::query()
                    ->whereKeyNot($template->id)
                    ->where('document_type', $data['document_type'])
                    ->where('branch_id', $data['branch_id'])
                    ->update(['is_default' => false]);
            }

            $template->update($data);
        });

        $this->bumpTemplateCacheVersion();

        return redirect()->route('sales.templates.index')->with('success', 'Plantilla actualizada correctamente.');
    }

    public function destroy(ReceiptTemplate $template): RedirectResponse
    {
        abort_unless($this->canManageTemplate(request(), $template), 403);

        $template->delete();

        $this->bumpTemplateCacheVersion();

        return redirect()->route('sales.templates.index')->with('success', 'Plantilla desactivada correctamente.');
    }

    private function bumpTemplateCacheVersion(): void
    {
        Cache::forever('receipt-template-version', now()->timestamp);
    }

    private function branches(Request $request)
    {
        return UiCatalogCache::activeBranchesForUser($request->user());
    }

    private function layoutWithCatalogFields(array $layout): array
    {
        $merged = array_replace_recursive(ReceiptTemplate::defaultLayout(), $layout);

        foreach ($this->attributeFields() as $attribute) {
            $merged['fields'][$attribute['field']] = $merged['fields'][$attribute['field']] ?? true;
        }

        $merged['item_columns'] = $this->normalizeItemColumns($merged);

        return $merged;
    }

    private function normalizeItemColumns(array $layout): array
    {
        $columns = collect(ReceiptTemplate::defaultLayout()['item_columns']);
        $nextOrder = 10;

        $columns = $columns->map(function (array $column) use ($layout, &$nextOrder) {
            $column['order'] = $nextOrder;
            $nextOrder += 10;

            return [
                ...$column,
                'show' => (bool) ($layout['fields'][$column['key']] ?? $column['show']),
            ];
        });

        foreach ($this->attributeFields() as $attribute) {
            $columns->push([
                'key' => $attribute['field'],
                'label' => $attribute['label'],
                'show' => (bool) ($layout['fields'][$attribute['field']] ?? true),
                'order' => $nextOrder,
            ]);
            $nextOrder += 10;
        }

        $savedColumns = collect($layout['item_columns'] ?? [])
            ->filter(fn ($column) => is_array($column) && filled($column['key'] ?? null))
            ->keyBy('key');

        return $columns
            ->map(function (array $column) use ($savedColumns) {
                $saved = $savedColumns->get($column['key'], []);

                return [
                    'key' => $column['key'],
                    'label' => filled($saved['label'] ?? null) ? $saved['label'] : $column['label'],
                    'show' => array_key_exists('show', $saved) ? (bool) $saved['show'] : $column['show'],
                    'order' => (int) ($saved['order'] ?? $column['order']),
                ];
            })
            ->sortBy('order')
            ->values()
            ->map(fn (array $column, int $index) => [...$column, 'order' => $index + 1])
            ->all();
    }

    private function attributeFields(): array
    {
        return UiCatalogCache::activeProductsWithThickness()
            ->flatMap(fn ($product) => $product->custom_attributes ?? [])
            ->filter(fn ($attribute) => filled($attribute['code'] ?? null))
            ->unique('code')
            ->map(fn ($attribute) => [
                'field' => 'item_attribute_'.$attribute['code'],
                'code' => $attribute['code'],
                'label' => $this->printableAttributeName($attribute['name'] ?? $attribute['code'], $attribute['unit'] ?? null),
            ])
            ->values()
            ->all();
    }

    private function printableAttributeName(string $name, ?string $unit): string
    {
        $label = trim(str_ireplace(' util', '', $name));

        return $unit ? "{$label} ({$unit})" : $label;
    }

    private function canManageTemplate(Request $request, ReceiptTemplate $template): bool
    {
        return $request->user()->isSuperAdministrator()
            || BranchAccess::canAccess($request->user(), (int) $template->branch_id);
    }

    private function canUseBranch(Request $request, ?int $branchId): bool
    {
        if ($request->user()->isSuperAdministrator()) {
            return true;
        }

        return BranchAccess::canAccess($request->user(), $branchId);
    }
}
