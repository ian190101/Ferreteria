<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\SiatBranchSetting;
use App\Modules\Billing\Models\SiatCufd;
use App\Modules\Billing\Models\SiatCuis;
use App\Support\BranchAccess;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class SiatSettingController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Billing/Settings/Index', [
            'settings' => SiatBranchSetting::query()
                ->with('branch:id,name')
                ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
                ->orderBy('branch_id')
                ->get()
                ->map(fn (SiatBranchSetting $setting) => $this->settingPayload($setting)),
            'branches' => UiCatalogCache::activeBranchesForUser($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'nit' => ['required', 'string', 'max:20'],
            'business_name' => ['required', 'string', 'max:255'],
            'municipality' => ['required', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:40'],
            'system_code' => ['required', 'string', 'max:120'],
            'environment_code' => ['required', 'integer', 'in:1,2'],
            'modality_code' => ['required', 'integer', 'in:1,2'],
            'emission_type_code' => ['required', 'integer', 'min:1'],
            'invoice_type_code' => ['required', 'integer', 'min:1'],
            'document_sector_code' => ['required', 'integer', 'min:1'],
            'economic_activity_code' => ['nullable', 'integer'],
            'sin_product_code' => ['nullable', 'integer'],
            'siat_branch_code' => ['required', 'integer', 'min:0'],
            'point_of_sale_code' => ['required', 'integer', 'min:0'],
            'token' => ['nullable', 'string'],
            'clear_token' => ['boolean'],
            'certificate_path' => ['nullable', 'string', 'max:255'],
            'certificate_password' => ['nullable', 'string'],
            'mock_siat' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        abort_unless(BranchAccess::canAccess($request->user(), (int) $data['branch_id']), 403);

        $setting = SiatBranchSetting::query()->firstOrNew(['branch_id' => $data['branch_id']]);
        $previous = $setting->exists ? $setting->replicate() : null;
        $hadToken = filled($setting->token_encrypted);

        $setting->fill([
            ...collect($data)->except(['token', 'clear_token', 'certificate_password', 'mock_siat'])->all(),
            'options' => ['mock_siat' => (bool) ($data['mock_siat'] ?? false)],
        ]);

        if ((bool) ($data['clear_token'] ?? false)) {
            $setting->token_encrypted = null;
        } elseif (filled($data['token'] ?? null)) {
            $setting->token = $data['token'];
        }

        if (filled($data['certificate_password'] ?? null)) {
            $setting->certificatePassword = $data['certificate_password'];
        }

        $setting->save();

        if ($this->requiresCodeRenewal($previous, $setting, $hadToken, filled($data['token'] ?? null), (bool) ($data['clear_token'] ?? false))) {
            $this->expireActiveCodes((int) $setting->branch_id);
        }

        return back()->with('success', 'Configuracion SIAT guardada correctamente. Si cambiaste NIT, token o ambiente, solicita CUIS y CUFD nuevamente.');
    }

    /**
     * @return array<string, mixed>
     */
    private function settingPayload(SiatBranchSetting $setting): array
    {
        return [
            ...Arr::except($setting->toArray(), ['token_encrypted', 'certificate_password_encrypted']),
            'branch' => $setting->branch,
            'has_token' => filled($setting->token_encrypted),
            'has_certificate_password' => filled($setting->certificate_password_encrypted),
            'environment_label' => (int) $setting->environment_code === SiatBranchSetting::ENVIRONMENT_PRODUCTION
                ? 'Produccion'
                : 'Piloto / pruebas',
            'modality_label' => (int) $setting->modality_code === SiatBranchSetting::MODALITY_ELECTRONIC
                ? 'Electronica en linea'
                : 'Computarizada en linea',
        ];
    }

    private function requiresCodeRenewal(?SiatBranchSetting $previous, SiatBranchSetting $current, bool $hadToken, bool $receivedNewToken, bool $clearToken): bool
    {
        if (! $previous) {
            return false;
        }

        $sensitiveFields = [
            'nit',
            'system_code',
            'environment_code',
            'modality_code',
            'emission_type_code',
            'invoice_type_code',
            'document_sector_code',
            'siat_branch_code',
            'point_of_sale_code',
        ];

        foreach ($sensitiveFields as $field) {
            if ((string) $previous->{$field} !== (string) $current->{$field}) {
                return true;
            }
        }

        return $receivedNewToken || ($clearToken && $hadToken);
    }

    private function expireActiveCodes(int $branchId): void
    {
        SiatCuis::query()
            ->where('branch_id', $branchId)
            ->where('status', SiatCuis::STATUS_ACTIVE)
            ->update(['status' => SiatCuis::STATUS_EXPIRED]);

        SiatCufd::query()
            ->where('branch_id', $branchId)
            ->where('status', SiatCufd::STATUS_ACTIVE)
            ->update(['status' => SiatCufd::STATUS_EXPIRED]);
    }
}
