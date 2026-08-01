<?php

namespace App\Modules\CashFlow\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CashFlow\Services\CashFlowReportService;
use App\Modules\Payments\Models\PaymentMethod;
use App\Support\UiCatalogCache;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CashFlowController extends Controller
{
    public function __invoke(Request $request, CashFlowReportService $report): Response
    {
        $payload = $report->build($request);

        return Inertia::render('CashFlow/Index', [
            ...$payload,
            'branches' => UiCatalogCache::activeBranchesForUser($request->user()),
            'paymentMethods' => PaymentMethod::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'code']),
            'canViewAllBranches' => $request->user()->isSuperAdministrator(),
        ]);
    }
}
