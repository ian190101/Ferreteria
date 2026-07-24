<?php

namespace App\Modules\HumanResources\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\HumanResources\Models\Worker;
use App\Support\BranchAccess;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;
use Inertia\Inertia;
use Inertia\Response;

class WorkerController extends Controller
{
    public function index(Request $request): Response
    {
        $workers = Worker::query()
            ->with([
                'branch:id,name',
                'user' => fn ($query) => $query->withoutSystemSuperadmins()->select('id', 'name', 'email'),
            ])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();
                $query->where(fn ($nested) => $nested
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('document_number', 'like', "%{$search}%")
                    ->orWhere('position', 'like', "%{$search}%"));
            })
            ->latest('id')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return Inertia::render('HumanResources/Workers/Index', [
            'workers' => $workers,
            'branches' => UiCatalogCache::activeBranchesForUser($request->user(), ['id', 'name']),
            'users' => User::query()
                ->withoutSystemSuperadmins()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
            'filters' => $request->only(['search', 'per_page']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        abort_unless(BranchAccess::canAccess($request->user(), (int) $data['branch_id']), 403);

        Worker::query()->create($data);

        return back()->with('success', 'Trabajador registrado correctamente.');
    }

    public function update(Request $request, Worker $worker): RedirectResponse
    {
        abort_unless(BranchAccess::canAccess($request->user(), (int) $worker->branch_id), 403);
        $data = $this->validated($request);
        abort_unless(BranchAccess::canAccess($request->user(), (int) $data['branch_id']), 403);

        $worker->update($data);

        return back()->with('success', 'Trabajador actualizado correctamente.');
    }

    public function destroy(Request $request, Worker $worker): RedirectResponse
    {
        abort_unless(BranchAccess::canAccess($request->user(), (int) $worker->branch_id), 403);
        $worker->update(['is_active' => false]);
        $worker->delete();

        return back()->with('success', 'Trabajador desactivado correctamente.');
    }

    private function validated(Request $request): array
    {
        $validator = validator($request->all(), [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:160'],
            'document_number' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:80'],
            'position' => ['nullable', 'string', 'max:120'],
            'hired_at' => ['nullable', 'date'],
            'salary_amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'salary_frequency' => ['required', 'string', 'in:weekly,biweekly,monthly,custom'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $validator->after(function (Validator $validator) use ($request) {
            if (! $request->filled('user_id')) {
                return;
            }

            $isVisibleUser = User::query()
                ->withoutSystemSuperadmins()
                ->whereKey($request->integer('user_id'))
                ->exists();

            if (! $isVisibleUser) {
                $validator->errors()->add('user_id', 'No puedes vincular este usuario.');
            }
        });

        return $validator->validate();
    }
}
