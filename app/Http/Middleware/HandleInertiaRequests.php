<?php

namespace App\Http\Middleware;

use App\Modules\Branches\Models\BranchSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;
use Spatie\Permission\Models\Permission;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => $this->auth($request),
            'branding' => $this->branding($request),
        ];
    }

    /**
     * Evita recalcular roles, permisos y sucursales en cada navegacion Inertia.
     */
    private function auth(Request $request): array
    {
        $user = $request->user();

        if (! $user) {
            return [
                'user' => null,
                'roles' => [],
                'permissions' => [],
            ];
        }

        $user->loadMissing(['branch:id,name', 'accessibleBranches:id,name']);
        $roles = $user->getRoleNames()->values()->all();

        // Los roles/permisos no se cachean aqui porque pueden cargarse por inserts externos en TiDB.
        $permissions = in_array('superadmin', $roles, true)
            ? Permission::query()->pluck('name')->values()->all()
            : $user->getAllPermissions()->pluck('name')->values()->all();

        return [
            'user' => [
                'id' => $user->id,
                'branch_id' => $user->branch_id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'branch' => $user->branch ? [
                    'id' => $user->branch->id,
                    'name' => $user->branch->name,
                ] : null,
                'accessible_branches' => $user->accessibleBranches
                    ->map(fn ($branch) => [
                        'id' => $branch->id,
                        'name' => $branch->name,
                    ])
                    ->values()
                    ->all(),
            ],
            'roles' => $roles,
            'permissions' => $permissions,
        ];
    }

    /**
     * Se cachea por sucursal porque el branding se consulta en cada carga Inertia.
     */
    private function branding(Request $request): array
    {
        $branchId = $request->user()?->branch_id;

        if (! $branchId) {
            return Cache::remember('public:branding', now()->addMinutes(30), function () {
                $setting = BranchSetting::query()
                    ->select(['branch_id', 'primary_color', 'secondary_color', 'logo_path', 'theme_mode'])
                    ->whereNotNull('logo_path')
                    ->where('logo_path', '!=', '')
                    ->orderBy('branch_id')
                    ->first();

                if (! $setting) {
                    return $this->defaultBranding();
                }

                return $this->brandingFromSetting($setting);
            });
        }

        return Cache::remember("branch:{$branchId}:branding", now()->addMinutes(30), function () use ($branchId) {
            $setting = BranchSetting::query()
                ->select(['branch_id', 'primary_color', 'secondary_color', 'logo_path', 'theme_mode'])
                ->where('branch_id', $branchId)
                ->first();

            if (! $setting) {
                return $this->defaultBranding();
            }

            return $this->brandingFromSetting($setting);
        });
    }

    private function brandingFromSetting(BranchSetting $setting): array
    {
        return [
            'primary' => $setting->primary_color,
            'secondary' => $setting->secondary_color,
            'primaryRgb' => $this->hexToRgb($setting->primary_color),
            'secondaryRgb' => $this->hexToRgb($setting->secondary_color),
            'logoPath' => $setting->logo_path,
            'themeMode' => $setting->theme_mode,
        ];
    }

    private function defaultBranding(): array
    {
        return [
            'primary' => '#2563eb',
            'secondary' => '#0f172a',
            'primaryRgb' => '37 99 235',
            'secondaryRgb' => '15 23 42',
            'logoPath' => null,
            'themeMode' => 'system',
        ];
    }

    private function hexToRgb(string $hex): string
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return '37 99 235';
        }

        return implode(' ', [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ]);
    }
}
