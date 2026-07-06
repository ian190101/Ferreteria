<?php

namespace App\Modules\Branches\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branches\Http\Requests\StoreBranchRequest;
use App\Modules\Branches\Http\Requests\UpdateBranchRequest;
use App\Modules\Branches\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends Controller
{
    public function index(Request $request): Response
    {
        $branches = Branch::query()
            ->with('setting')
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request) {
                $search = $request->string('search')->toString();

                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%");
                });
            })
            ->latest('id')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return Inertia::render('Branches/Index', [
            'branches' => $branches,
            'filters' => $request->only('search', 'per_page'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Branches/Form', [
            'branch' => null,
        ]);
    }

    public function store(StoreBranchRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $branch = Branch::query()->create($request->safe()->except('setting'));
            $branch->setting()->create($request->validated('setting'));
        });

        return redirect()->route('branches.index')->with('success', 'Sucursal creada correctamente.');
    }

    public function edit(Branch $branch): Response
    {
        return Inertia::render('Branches/Form', [
            'branch' => $branch->load('setting'),
        ]);
    }

    public function update(UpdateBranchRequest $request, Branch $branch): RedirectResponse
    {
        DB::transaction(function () use ($request, $branch) {
            $branch->update($request->safe()->except('setting'));
            $branch->setting()->updateOrCreate(
                ['branch_id' => $branch->id],
                $request->validated('setting'),
            );
        });

        Cache::forget("branch:{$branch->id}:branding");

        return redirect()->route('branches.index')->with('success', 'Sucursal actualizada correctamente.');
    }

    public function destroy(Request $request, Branch $branch): RedirectResponse
    {
        abort_unless($request->user()?->can('branches.manage'), 403);

        if ($request->user()?->branch_id === $branch->id) {
            return back()->withErrors(['branch' => 'No puedes desactivar la sucursal de tu usuario actual.']);
        }

        $branch->update(['is_active' => false]);
        Cache::forget("branch:{$branch->id}:branding");

        return redirect()->route('branches.index')->with('success', 'Sucursal desactivada correctamente.');
    }
}
