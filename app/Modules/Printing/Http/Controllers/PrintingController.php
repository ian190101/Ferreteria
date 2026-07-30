<?php

namespace App\Modules\Printing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Printing\Models\PrintDocumentTemplate;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Models\PrintRule;
use App\Modules\Printing\Services\PrintRenderService;
use App\Modules\SystemSuperadmin\Models\PrinterProfile;
use App\Support\BranchAccess;
use App\Support\SystemCacheInvalidator;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PrintingController extends Controller
{
    public function index(Request $request, PrintRenderService $renderer): Response
    {
        $branches = UiCatalogCache::activeBranchesForUser($request->user(), ['id', 'name']);
        $branchIds = $request->user()->isSuperAdministrator() ? [] : ($request->user()->accessibleBranchIds() ?: [-1]);

        $templates = PrintDocumentTemplate::query()
            ->with('branch:id,name')
            ->when($request->filled('document_type'), fn ($query) => $query->where('document_type', $request->string('document_type')->toString()))
            ->where(fn ($query) => $query->whereNull('branch_id')->when($branchIds !== [], fn ($nested) => $nested->orWhereIn('branch_id', $branchIds)))
            ->orderBy('document_type')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 12), ['*'], 'templates_page')
            ->withQueryString();

        $printerProfiles = PrinterProfile::query()
            ->with('branch:id,name')
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('branch_id')->when($branchIds !== [], fn ($nested) => $nested->orWhereIn('branch_id', $branchIds)))
            ->orderBy('area')
            ->orderBy('name')
            ->get(['id', 'branch_id', 'code', 'name', 'area', 'paper_type', 'thermal_width_mm', 'copies', 'auto_print', 'is_active']);

        $rules = PrintRule::query()
            ->with(['branch:id,name', 'printerProfile:id,name,area', 'template:id,name,document_type'])
            ->where(fn ($query) => $query->whereNull('branch_id')->when($branchIds !== [], fn ($nested) => $nested->orWhereIn('branch_id', $branchIds)))
            ->orderBy('document_type')
            ->orderBy('area')
            ->get();

        $jobs = PrintJob::query()
            ->with(['branch:id,name', 'printerProfile:id,name', 'template:id,name', 'user:id,name'])
            ->where(fn ($query) => $query->whereNull('branch_id')->when($branchIds !== [], fn ($nested) => $nested->orWhereIn('branch_id', $branchIds)))
            ->latest()
            ->limit(30)
            ->get();

        $previewTemplate = PrintDocumentTemplate::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('branch_id')->when($branchIds !== [], fn ($nested) => $nested->orWhereIn('branch_id', $branchIds)))
            ->orderByDesc('is_default')
            ->orderByDesc('updated_at')
            ->first();

        return Inertia::render('Printing/Index', [
            'branches' => $branches,
            'documentTypes' => $renderer->documentTypes(),
            'areas' => $renderer->areas(),
            'paperTypes' => $renderer->paperTypes(),
            'triggers' => $renderer->triggers(),
            'templates' => $templates,
            'printerProfiles' => $printerProfiles,
            'rules' => $rules,
            'jobs' => $jobs,
            'previewHtml' => $previewTemplate ? (string) $renderer->render($previewTemplate) : '',
            'filters' => $request->only(['document_type', 'per_page']),
        ]);
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        $data = $this->validateTemplate($request);

        abort_unless($this->canUseBranch($request, $data['branch_id'] ?? null), 403);

        DB::transaction(function () use ($data) {
            if ($data['is_default']) {
                PrintDocumentTemplate::query()
                    ->where('document_type', $data['document_type'])
                    ->where('branch_id', $data['branch_id'] ?? null)
                    ->update(['is_default' => false]);
            }

            PrintDocumentTemplate::query()->create($data);
        });

        SystemCacheInvalidator::bumpOperational();

        return back()->with('success', 'Plantilla de impresion creada correctamente.');
    }

    public function storePrinter(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'code' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:160'],
            'area' => ['required', 'string', Rule::in(array_keys(app(PrintRenderService::class)->areas()))],
            'paper_type' => ['required', 'string', Rule::in(array_keys(app(PrintRenderService::class)->paperTypes()))],
            'thermal_width_mm' => ['nullable', 'integer', 'min:40', 'max:120'],
            'copies' => ['required', 'integer', 'min:1', 'max:5'],
            'auto_print' => ['boolean'],
        ], [], [
            'branch_id' => 'sucursal',
            'code' => 'codigo',
            'name' => 'nombre',
            'area' => 'area',
            'paper_type' => 'tipo de papel',
            'copies' => 'copias',
        ]);

        abort_unless($this->canUseBranch($request, $data['branch_id'] ?? null), 403);

        PrinterProfile::query()->updateOrCreate(
            ['branch_id' => $data['branch_id'] ?? null, 'code' => $data['code']],
            [
                ...$data,
                'auto_print' => (bool) ($data['auto_print'] ?? false),
                'settings' => ['managed_from' => 'printing_module'],
                'is_active' => true,
            ],
        );

        SystemCacheInvalidator::bumpOperational();

        return back()->with('success', 'Perfil de impresora guardado correctamente.');
    }

    public function storeRule(Request $request): RedirectResponse
    {
        $renderer = app(PrintRenderService::class);
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'printer_profile_id' => ['nullable', 'integer', 'exists:printer_profiles,id'],
            'print_document_template_id' => ['nullable', 'integer', 'exists:print_document_templates,id'],
            'document_type' => ['required', 'string', Rule::in(array_keys($renderer->documentTypes()))],
            'area' => ['required', 'string', Rule::in(array_keys($renderer->areas()))],
            'trigger' => ['required', 'string', Rule::in(array_keys($renderer->triggers()))],
            'copies' => ['required', 'integer', 'min:1', 'max:5'],
            'auto_print' => ['boolean'],
            'is_active' => ['boolean'],
        ], [], [
            'printer_profile_id' => 'perfil de impresora',
            'print_document_template_id' => 'plantilla',
            'document_type' => 'documento',
            'trigger' => 'disparador',
        ]);

        abort_unless($this->canUseBranch($request, $data['branch_id'] ?? null), 403);
        $this->assertRelatedBranch($request, PrinterProfile::query()->find($data['printer_profile_id'] ?? 0)?->branch_id);
        $this->assertRelatedBranch($request, PrintDocumentTemplate::query()->find($data['print_document_template_id'] ?? 0)?->branch_id);

        PrintRule::query()->updateOrCreate(
            [
                'branch_id' => $data['branch_id'] ?? null,
                'document_type' => $data['document_type'],
                'area' => $data['area'],
                'trigger' => $data['trigger'],
            ],
            [
                ...$data,
                'auto_print' => (bool) ($data['auto_print'] ?? false),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'conditions' => [],
            ],
        );

        SystemCacheInvalidator::bumpOperational();

        return back()->with('success', 'Regla de impresion guardada correctamente.');
    }

    public function queueJob(Request $request, PrintRenderService $renderer): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'printer_profile_id' => ['nullable', 'integer', 'exists:printer_profiles,id'],
            'print_document_template_id' => ['nullable', 'integer', 'exists:print_document_templates,id'],
            'document_type' => ['required', 'string', Rule::in(array_keys($renderer->documentTypes()))],
            'area' => ['required', 'string', Rule::in(array_keys($renderer->areas()))],
            'copies' => ['required', 'integer', 'min:1', 'max:5'],
        ]);

        abort_unless($this->canUseBranch($request, $data['branch_id'] ?? null), 403);
        $template = $this->resolveTemplate($data);
        $printer = PrinterProfile::query()->find($data['printer_profile_id'] ?? null);

        $this->assertRelatedBranch($request, $template?->branch_id);
        $this->assertRelatedBranch($request, $printer?->branch_id);

        PrintJob::query()->create([
            'branch_id' => $data['branch_id'] ?? null,
            'printer_profile_id' => $printer?->id,
            'print_document_template_id' => $template?->id,
            'user_id' => $request->user()->id,
            'document_type' => $data['document_type'],
            'area' => $data['area'],
            'status' => PrintJob::STATUS_QUEUED,
            'copies' => $data['copies'],
            'payload' => $renderer->defaultPayload($data['document_type']),
            'rendered_preview' => $template ? (string) $renderer->render($template) : null,
        ]);

        SystemCacheInvalidator::bumpOperational();

        return back()->with('success', 'Trabajo de impresion enviado a la cola.');
    }

    public function markPrinted(Request $request, PrintJob $job): RedirectResponse
    {
        abort_unless($this->canUseBranch($request, $job->branch_id), 403);

        $job->update([
            'status' => PrintJob::STATUS_PRINTED,
            'printed_at' => now(),
            'error_message' => null,
        ]);

        SystemCacheInvalidator::bumpOperational();

        return back()->with('success', 'Trabajo marcado como impreso.');
    }

    private function validateTemplate(Request $request): array
    {
        $renderer = app(PrintRenderService::class);
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'document_type' => ['required', 'string', Rule::in(array_keys($renderer->documentTypes()))],
            'name' => ['required', 'string', 'max:160'],
            'paper_type' => ['required', 'string', Rule::in(array_keys($renderer->paperTypes()))],
            'thermal_width_mm' => ['nullable', 'integer', 'min:40', 'max:120'],
            'font_size' => ['required', 'integer', 'min:8', 'max:18'],
            'margin_mm' => ['required', 'integer', 'min:0', 'max:20'],
            'show_logo' => ['boolean'],
            'show_barcode' => ['boolean'],
            'color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['string', 'max:80'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ], [], [
            'document_type' => 'tipo de documento',
            'paper_type' => 'tipo de papel',
            'font_size' => 'tamano de letra',
            'margin_mm' => 'margen',
            'color' => 'color',
        ]);

        $defaultLayout = PrintDocumentTemplate::defaultLayout($data['document_type']);

        return [
            'branch_id' => $data['branch_id'] ?? null,
            'document_type' => $data['document_type'],
            'name' => $data['name'],
            'paper_type' => $data['paper_type'],
            'thermal_width_mm' => $data['thermal_width_mm'] ?? null,
            'layout' => [
                ...$defaultLayout,
                'font_size' => (int) $data['font_size'],
                'margin_mm' => (int) $data['margin_mm'],
                'show_logo' => (bool) ($data['show_logo'] ?? false),
                'show_barcode' => (bool) ($data['show_barcode'] ?? false),
                'color' => $data['color'],
                'fields' => array_values(array_filter($data['fields'] ?? $defaultLayout['fields'])),
            ],
            'is_default' => (bool) ($data['is_default'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    private function resolveTemplate(array $data): ?PrintDocumentTemplate
    {
        if (! empty($data['print_document_template_id'])) {
            return PrintDocumentTemplate::query()->find($data['print_document_template_id']);
        }

        return PrintDocumentTemplate::query()
            ->where('document_type', $data['document_type'])
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('branch_id', $data['branch_id'] ?? null)->orWhereNull('branch_id'))
            ->orderByRaw('case when branch_id is null then 1 else 0 end')
            ->orderByDesc('is_default')
            ->latest('updated_at')
            ->first();
    }

    private function canUseBranch(Request $request, ?int $branchId): bool
    {
        return $branchId === null || BranchAccess::canAccess($request->user(), $branchId);
    }

    private function assertRelatedBranch(Request $request, ?int $branchId): void
    {
        abort_unless($this->canUseBranch($request, $branchId), 403);
    }
}
