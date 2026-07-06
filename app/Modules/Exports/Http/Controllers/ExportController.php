<?php

namespace App\Modules\Exports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Exports\Services\ExportDatasetService;
use App\Modules\Exports\Services\SimplePdfExporter;
use App\Modules\Exports\Services\SimpleXlsxExporter;
use App\Support\UiCatalogCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportController extends Controller
{
    public function index(ExportDatasetService $datasets): Response
    {
        return Inertia::render('Exports/Index', [
            'catalog' => $datasets->catalog(),
            'branches' => UiCatalogCache::activeBranchesForUser(request()->user()),
            'defaults' => [
                'from' => now()->startOfMonth()->toDateString(),
                'to' => now()->toDateString(),
            ],
            'csrfToken' => csrf_token(),
        ]);
    }

    public function download(Request $request, ExportDatasetService $datasets, SimpleXlsxExporter $xlsx, SimplePdfExporter $pdf): BinaryFileResponse
    {
        $validated = $request->validate([
            'format' => ['required', 'in:xlsx,pdf'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'modules' => ['required', 'array', 'min:1'],
            'modules.*' => ['string'],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['array'],
            'fields.*.*' => ['string'],
        ]);

        $dataset = $datasets->build($request->merge($validated));
        $format = $validated['format'];
        $filename = 'exportacion-'.now()->format('Ymd-His').'.'.$format;
        $relativePath = 'exports/'.$filename;
        $absolutePath = Storage::disk('local')->path($relativePath);

        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0755, true);
        }

        if ($format === 'xlsx') {
            $xlsx->save($dataset, $absolutePath);
            $contentType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        } else {
            $pdf->save($dataset, $absolutePath);
            $contentType = 'application/pdf';
        }

        return response()->download($absolutePath, $filename, ['Content-Type' => $contentType])->deleteFileAfterSend(true);
    }
}
