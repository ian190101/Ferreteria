<?php

namespace App\Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Users\Http\Requests\StoreRoleRequest;
use App\Modules\Users\Http\Requests\UpdateRoleRequest;
use App\Support\AuthSessionCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        $roles = Role::query()
            ->with('permissions:id,name')
            ->withCount('users')
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request) {
                $query->where('name', 'like', '%'.$request->string('search')->toString().'%');
            })
            ->latest('id')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return Inertia::render('Users/Roles/Index', [
            'roles' => $roles,
            'filters' => $request->only('search', 'per_page'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Users/Roles/Form', [
            'role' => null,
            'permissions' => $this->permissions(),
        ]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $role = Role::query()->create([
            'name' => $request->string('name')->toString(),
            'guard_name' => 'web',
        ]);

        $role->syncPermissions($request->input('permissions'));
        $this->bumpAuthCacheVersion();

        return redirect()->route('users.roles.index')->with('success', 'Rol creado correctamente.');
    }

    public function edit(Role $role): Response
    {
        return Inertia::render('Users/Roles/Form', [
            'role' => $role->load('permissions:id,name'),
            'permissions' => $this->permissions(),
        ]);
    }

    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        $role->update([
            'name' => $request->string('name')->toString(),
        ]);
        $role->syncPermissions($request->input('permissions'));
        $this->bumpAuthCacheVersion();

        return redirect()->route('users.roles.index')->with('success', 'Rol actualizado correctamente.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->name === 'superadmin') {
            return back()->withErrors(['role' => 'El rol superadmin no puede eliminarse.']);
        }

        $role->delete();
        $this->bumpAuthCacheVersion();

        return redirect()->route('users.roles.index')->with('success', 'Rol eliminado correctamente.');
    }

    private function permissions()
    {
        return Permission::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->groupBy(fn (Permission $permission) => str($permission->name)->before('.')->toString());
    }

    private function bumpAuthCacheVersion(): void
    {
        AuthSessionCache::bump();
    }
}
