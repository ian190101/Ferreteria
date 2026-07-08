<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Http\Requests\StoreReceiptTemplateRequest;
use App\Modules\Sales\Http\Requests\UpdateReceiptTemplateRequest;
use App\Modules\Sales\Models\ReceiptTemplate;
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
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Sales/Templates/Form', [
            'template' => null,
            'branches' => $this->branches($request),
            'defaultLayout' => $this->layoutWithCatalogFields(ReceiptTemplate::defaultLayout()),
            'attributeFields' => $this->attributeFields(),
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

        return $merged;
    }

    private function attributeFields(): array
    {
        return UiCatalogCache::productCategories()
            ->flatMap(fn ($category) => $category->attributes)
            ->unique('code')
            ->map(fn ($attribute) => [
                'field' => 'item_attribute_'.$attribute->code,
                'code' => $attribute->code,
                'label' => $this->printableAttributeName($attribute->name, $attribute->unit?->symbol),
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
