<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Services\SiatCatalogSyncService;
use App\Modules\Billing\Services\SiatCodeService;
use App\Support\BranchAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SiatCodeController extends Controller
{
    public function cuis(Request $request, SiatCodeService $codes): RedirectResponse
    {
        $branchId = $request->integer('branch_id');
        abort_unless(BranchAccess::canAccess($request->user(), $branchId), 403);
        $codes->requestCuis($branchId);

        return back()->with('success', 'CUIS solicitado correctamente.');
    }

    public function cufd(Request $request, SiatCodeService $codes): RedirectResponse
    {
        $branchId = $request->integer('branch_id');
        abort_unless(BranchAccess::canAccess($request->user(), $branchId), 403);
        $codes->requestCufd($branchId);

        return back()->with('success', 'CUFD solicitado correctamente.');
    }

    public function syncCatalogs(Request $request, SiatCatalogSyncService $sync): RedirectResponse
    {
        $branchId = $request->integer('branch_id');
        abort_unless(BranchAccess::canAccess($request->user(), $branchId), 403);
        $result = $sync->syncCoreCatalogs($branchId);

        return back()->with('success', 'Catalogos SIAT sincronizados: '.collect($result)->map(fn ($count, $type) => "{$type}: {$count}")->implode(', '));
    }
}
