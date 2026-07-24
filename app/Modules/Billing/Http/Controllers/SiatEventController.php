<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\SiatInvoice;
use App\Modules\Billing\Models\SiatSignificantEvent;
use App\Modules\Billing\Services\SiatEventService;
use App\Modules\Billing\Services\SiatPackageService;
use App\Support\BranchAccess;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class SiatEventController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Billing/Events/Index', [
            'branches' => UiCatalogCache::activeBranchesForUser($request->user(), ['id', 'name']),
            'events' => SiatSignificantEvent::query()
                ->with('branch:id,name')
                ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
                ->latest('started_at')
                ->paginate(15),
        ]);
    }

    public function store(Request $request, SiatEventService $events): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'event_code' => ['required', 'integer'],
            'started_at' => ['required', 'date'],
            'ended_at' => ['required', 'date', 'after_or_equal:started_at'],
            'description' => ['required', 'string', 'max:500'],
        ]);

        abort_unless(BranchAccess::canAccess($request->user(), (int) $data['branch_id']), 403);

        $events->register(
            (int) $data['branch_id'],
            (int) $data['event_code'],
            Carbon::parse($data['started_at']),
            Carbon::parse($data['ended_at']),
            $data['description'],
        );

        return back()->with('success', 'Evento significativo registrado en SIAT.');
    }

    public function package(Request $request, SiatSignificantEvent $event, SiatPackageService $packages): RedirectResponse
    {
        abort_unless(BranchAccess::canAccess($request->user(), (int) $event->branch_id), 403);

        $invoices = SiatInvoice::query()
            ->where('branch_id', $event->branch_id)
            ->whereIn('status', [SiatInvoice::STATUS_CONTINGENCY, SiatInvoice::STATUS_TEMPORARY])
            ->whereBetween('issued_at', [$event->started_at, $event->ended_at ?? now()])
            ->limit(500)
            ->get();

        if ($invoices->isEmpty()) {
            return back()->with('error', 'No hay facturas temporales o de contingencia para empaquetar en este evento.');
        }

        $packages->buildAndSend($event, $invoices);

        return back()->with('success', 'Paquete de contingencia generado y enviado.');
    }
}
