<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\SiatBranchSetting;
use App\Modules\Billing\Models\SiatCatalogItem;
use App\Modules\Billing\Models\SiatCufd;
use App\Modules\Billing\Models\SiatCuis;
use App\Modules\Billing\Models\SiatInvoice;
use App\Support\BranchAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BillingDashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $settings = SiatBranchSetting::query()
            ->with('branch:id,name')
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->orderBy('branch_id')
            ->get();

        $invoices = SiatInvoice::query()
            ->with(['branch:id,name', 'sale:id,receipt_number'])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->latest('issued_at')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        $invoiceStats = fn () => SiatInvoice::query()
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()));
        $branchStats = fn () => SiatBranchSetting::query()
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()));

        return Inertia::render('Billing/Invoices/Index', [
            'settings' => $settings,
            'invoices' => $invoices,
            'stats' => [
                'validated' => (clone $invoiceStats())->where('status', SiatInvoice::STATUS_VALIDATED)->count(),
                'observed' => (clone $invoiceStats())->where('status', SiatInvoice::STATUS_OBSERVED)->count(),
                'pending' => (clone $invoiceStats())->where('status', SiatInvoice::STATUS_PENDING)->count(),
                'catalogs' => SiatCatalogItem::query()->count(),
                'cuis' => SiatCuis::query()
                    ->whereIn('branch_id', (clone $branchStats())->pluck('branch_id'))
                    ->where('status', SiatCuis::STATUS_ACTIVE)
                    ->count(),
                'cufd' => SiatCufd::query()
                    ->whereIn('branch_id', (clone $branchStats())->pluck('branch_id'))
                    ->where('status', SiatCufd::STATUS_ACTIVE)
                    ->count(),
            ],
        ]);
    }
}
