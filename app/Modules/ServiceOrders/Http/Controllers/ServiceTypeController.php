<?php

namespace App\Modules\ServiceOrders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ServiceOrders\Models\ServiceType;
use App\Support\SystemCacheInvalidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ServiceTypeController extends Controller
{
    public function index(Request $request): Response
    {
        $perPage = min(max($request->integer('per_page', 12), 6), 50);

        $types = ServiceType::query()
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->string('status')->toString() === 'active'))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();
                $query->where(fn ($nested) => $nested
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%"));
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('ServiceOrders/Types/Index', [
            'types' => $types,
            'filters' => $request->only(['search', 'status', 'per_page']),
            'deliveryCode' => ServiceType::DELIVERY_CODE,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        ServiceType::query()->create($data);
        SystemCacheInvalidator::bumpOperational();

        return back()->with('success', 'Tipo de servicio creado correctamente.');
    }

    public function update(Request $request, ServiceType $serviceType): RedirectResponse
    {
        $data = $this->validated($request, $serviceType);

        if ($serviceType->is_system) {
            unset($data['code'], $data['is_system'], $data['is_delivery']);
        }

        $serviceType->update($data);
        SystemCacheInvalidator::bumpOperational();

        return back()->with('success', 'Tipo de servicio actualizado correctamente.');
    }

    public function destroy(ServiceType $serviceType): RedirectResponse
    {
        if (! $serviceType->canBeDeleted()) {
            throw ValidationException::withMessages([
                'service_type' => 'Este tipo de servicio es del sistema y no se puede borrar. Puedes desactivarlo desde su estado.',
            ]);
        }

        $serviceType->delete();
        SystemCacheInvalidator::bumpOperational();

        return back()->with('success', 'Tipo de servicio eliminado correctamente.');
    }

    private function validated(Request $request, ?ServiceType $current = null): array
    {
        $data = $request->validate([
            'code' => [
                'nullable',
                'string',
                'max:80',
                'regex:/^[a-z0-9_\\-]+$/',
                Rule::unique('service_types', 'code')->ignore($current?->id),
            ],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'billing_unit' => ['required', 'string', Rule::in(['service', 'hour', 'day', 'route', 'session', 'project'])],
            'requires_materials' => ['boolean'],
            'requires_responsible' => ['boolean'],
            'requires_schedule' => ['boolean'],
            'is_delivery' => ['boolean'],
            'is_active' => ['boolean'],
            'settings' => ['nullable', 'array'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ], [], [
            'code' => 'codigo',
            'name' => 'nombre',
            'billing_unit' => 'unidad de cobro',
            'requires_materials' => 'requiere materiales',
            'requires_responsible' => 'requiere responsable',
            'requires_schedule' => 'requiere agenda',
            'is_delivery' => 'es delivery',
            'is_active' => 'estado',
        ]);

        $data['code'] = filled($data['code'] ?? null) ? Str::slug($data['code'], '_') : Str::slug($data['name'], '_');

        $codeExists = ServiceType::query()
            ->where('code', $data['code'])
            ->when($current, fn ($query) => $query->whereKeyNot($current->id))
            ->exists();

        if ($codeExists) {
            throw ValidationException::withMessages([
                'code' => 'Ya existe un tipo de servicio con este codigo normalizado.',
            ]);
        }

        $data['requires_materials'] = (bool) ($data['requires_materials'] ?? false);
        $data['requires_responsible'] = (bool) ($data['requires_responsible'] ?? false);
        $data['requires_schedule'] = (bool) ($data['requires_schedule'] ?? false);
        $data['is_delivery'] = (bool) ($data['is_delivery'] ?? false);
        $data['is_active'] = (bool) ($data['is_active'] ?? true);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['settings'] = collect($data['settings'] ?? [])
            ->filter(fn ($value, $key) => is_string($key) && preg_match('/^[a-zA-Z0-9_.-]+$/', $key))
            ->all();

        return $data;
    }
}
