<?php

namespace App\Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Users\Http\Requests\StoreUserRequest;
use App\Modules\Users\Http\Requests\UpdateUserRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $users = User::query()
            ->with(['branch:id,name', 'accessibleBranches:id,name', 'roles:id,name'])
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request) {
                $search = $request->string('search')->toString();

                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->latest('id')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return Inertia::render('Users/Index', [
            'users' => $users,
            'filters' => $request->only('search', 'per_page'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Users/Form', [
            'userRecord' => null,
            'branches' => $this->branches(),
            'roles' => $this->roles(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $branchIds = $this->normalizedBranchIds($data);
        $data['branch_id'] ??= $branchIds->first();
        $data['force_password_change'] = true;

        $user = User::query()->create(Arr::except($data, ['roles', 'branch_ids', 'password_confirmation']));
        $user->syncRoles($data['roles']);
        $user->accessibleBranches()->sync($branchIds);
        $this->bumpAuthCacheVersion();

        return redirect()->route('users.index')->with('success', 'Usuario creado correctamente.');
    }

    public function edit(User $user): Response
    {
        return Inertia::render('Users/Form', [
            'userRecord' => $user->load(['branch:id,name', 'accessibleBranches:id,name', 'roles:id,name']),
            'branches' => $this->branches(),
            'roles' => $this->roles(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        if ($request->user()->is($user) && ! $data['is_active']) {
            return back()->withErrors(['is_active' => 'No puedes desactivar tu propio usuario.']);
        }

        $passwordWasChanged = filled($data['password'] ?? null);

        if (! $passwordWasChanged) {
            unset($data['password']);
        } else {
            $data['force_password_change'] = true;
        }

        $branchIds = $this->normalizedBranchIds($data);
        $data['branch_id'] ??= $branchIds->first();

        $user->update(Arr::except($data, ['roles', 'branch_ids', 'password_confirmation']));
        $user->syncRoles($data['roles']);
        $user->accessibleBranches()->sync($branchIds);
        $this->bumpAuthCacheVersion();

        return redirect()->route('users.index')->with('success', 'Usuario actualizado correctamente.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()?->can('users.manage'), 403);

        if ($request->user()->is($user)) {
            return back()->withErrors(['user' => 'No puedes desactivar tu propio usuario.']);
        }

        $user->update(['is_active' => false]);
        $this->bumpAuthCacheVersion();

        return redirect()->route('users.index')->with('success', 'Usuario desactivado correctamente.');
    }

    private function branches()
    {
        return Branch::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function roles()
    {
        return Role::query()
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function normalizedBranchIds(array $data)
    {
        return collect($data['branch_ids'] ?? [])
            ->push($data['branch_id'] ?? null)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    private function bumpAuthCacheVersion(): void
    {
        Cache::forever('inertia-auth-version', now()->timestamp);
    }
}
