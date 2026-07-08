<?php

namespace App\Support;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;

class AuthSessionCache
{
    private const VERSION_KEY = 'inertia-auth-version';
    private const TTL_MINUTES = 15;

    /**
     * Memoria por request para evitar viajes repetidos a Redis dentro de la misma carga.
     *
     * @var array<string, mixed>
     */
    private static array $memory = [];

    public static function version(): string
    {
        return (string) (self::$memory[self::VERSION_KEY] ??= Cache::get(self::VERSION_KEY, '1'));
    }

    public static function bump(): void
    {
        $version = now()->format('Uu');

        self::$memory = [self::VERSION_KEY => $version];
        Cache::forever(self::VERSION_KEY, $version);
        Cache::forget('permissions:all-names');
    }

    /**
     * Centraliza el payload global de Inertia para no recalcular roles, permisos y sucursales en cada carga.
     *
     * @return array<string, mixed>
     */
    public static function payloadFor(User $user): array
    {
        $key = self::key('payload', $user);

        return self::remember(
            $key,
            now()->addMinutes(self::TTL_MINUTES),
            function () use ($user) {
                $branches = self::accessibleBranchSummariesFor($user);
                $primaryBranch = collect($branches)->firstWhere('id', (int) $user->branch_id);

                return [
                    'user' => [
                        'id' => $user->id,
                        'branch_id' => $user->branch_id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'is_active' => $user->is_active,
                        'branch' => $primaryBranch,
                        'accessible_branches' => $branches,
                    ],
                    'roles' => self::roleNamesFor($user),
                    'permissions' => self::permissionNamesFor($user),
                ];
            }
        );
    }

    /**
     * @return array<int, string>
     */
    public static function roleNamesFor(User $user): array
    {
        return self::remember(
            self::key('roles', $user),
            now()->addMinutes(self::TTL_MINUTES),
            fn () => $user->getRoleNames()->values()->all()
        );
    }

    public static function isSuperAdministrator(User $user): bool
    {
        return in_array('superadmin', self::roleNamesFor($user), true);
    }

    /**
     * @return array<int, string>
     */
    public static function permissionNamesFor(User $user): array
    {
        if (self::isSuperAdministrator($user)) {
            return self::allPermissionNames();
        }

        return self::remember(
            self::key('permissions', $user),
            now()->addMinutes(self::TTL_MINUTES),
            fn () => $user->getAllPermissions()->pluck('name')->values()->all()
        );
    }

    /**
     * @return array<int, string>
     */
    public static function allPermissionNames(): array
    {
        return self::remember('permissions:all-names', now()->addMinutes(30), fn () => Permission::query()
            ->orderBy('name')
            ->pluck('name')
            ->values()
            ->all());
    }

    /**
     * @return array<int>
     */
    public static function accessibleBranchIdsFor(User $user): array
    {
        return collect(self::accessibleBranchSummariesFor($user))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id:int,name:string}>
     */
    public static function accessibleBranchSummariesFor(User $user): array
    {
        return self::remember(
            self::key('branches:'.SystemCacheInvalidator::operationalVersion(), $user),
            now()->addMinutes(self::TTL_MINUTES),
            function () use ($user) {
                $branchIds = collect([$user->branch_id])
                    ->merge($user->accessibleBranches()->pluck('branches.id'))
                    ->filter()
                    ->unique()
                    ->values();

                if ($branchIds->isEmpty()) {
                    return [];
                }

                return Branch::query()
                    ->whereIn('id', $branchIds)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Branch $branch) => [
                        'id' => (int) $branch->id,
                        'name' => $branch->name,
                    ])
                    ->values()
                    ->all();
            }
        );
    }

    private static function key(string $scope, User $user): string
    {
        $updatedAt = $user->updated_at?->timestamp ?? 0;

        return "auth-session:{$scope}:v".self::version().":u{$user->id}:{$updatedAt}";
    }

    private static function remember(string $key, mixed $ttl, callable $callback): mixed
    {
        if (array_key_exists($key, self::$memory)) {
            return self::$memory[$key];
        }

        return self::$memory[$key] = Cache::remember($key, $ttl, $callback);
    }
}
