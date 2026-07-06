<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\StoreThicknessRequest;
use App\Modules\Inventory\Http\Requests\UpdateThicknessRequest;
use App\Modules\Inventory\Models\Thickness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ThicknessController extends Controller
{
    public function index(Request $request): Response
    {
        $thicknesses = Thickness::query()
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request) {
                $search = $request->string('search')->toString();

                $query->where('name', 'like', "%{$search}%");
            })
            ->orderBy('millimeters')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return Inertia::render('Inventory/Thicknesses/Index', [
            'thicknesses' => $thicknesses,
            'filters' => $request->only('search', 'per_page'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/Thicknesses/Form', [
            'thickness' => null,
        ]);
    }

    public function store(StoreThicknessRequest $request): RedirectResponse
    {
        Thickness::query()->create($request->validated());

        return redirect()
            ->route('inventory.thicknesses.index')
            ->with('success', 'Espesor creado correctamente.');
    }

    public function edit(Thickness $thickness): Response
    {
        return Inertia::render('Inventory/Thicknesses/Form', [
            'thickness' => $thickness,
        ]);
    }

    public function update(UpdateThicknessRequest $request, Thickness $thickness): RedirectResponse
    {
        $thickness->update($request->validated());

        return redirect()
            ->route('inventory.thicknesses.index')
            ->with('success', 'Espesor actualizado correctamente.');
    }

    public function destroy(Thickness $thickness): RedirectResponse
    {
        abort_unless(request()->user()?->can('inventory.products.manage'), 403);

        $thickness->delete();

        return redirect()
            ->route('inventory.thicknesses.index')
            ->with('success', 'Espesor desactivado correctamente.');
    }
}
