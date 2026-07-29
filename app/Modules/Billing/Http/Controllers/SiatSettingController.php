<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\SiatBranchSetting;
use App\Modules\Billing\Models\SiatCufd;
use App\Modules\Billing\Models\SiatCuis;
use App\Modules\Billing\Services\SiatCertificationReadinessService;
use App\Modules\Billing\Services\SiatInternalTestPresetService;
use App\Support\BranchAccess;
use App\Support\SystemRoles;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class SiatSettingController extends Controller
{
    public function index(Request $request, SiatCertificationReadinessService $readiness, SiatInternalTestPresetService $presets): Response
    {
        $settings = SiatBranchSetting::query()
            ->with('branch:id,name')
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->orderBy('branch_id')
            ->get();

        return Inertia::render('Billing/Settings/Index', [
            'settings' => $settings->map(fn (SiatBranchSetting $setting) => [
                ...$this->settingPayload($setting),
                'readiness' => $readiness->checklist((int) $setting->branch_id),
            ]),
            'branches' => UiCatalogCache::activeBranchesForUser($request->user()),
            'isSystemSuperadmin' => $request->user()->hasRole(SystemRoles::SYSTEM_SUPERADMIN),
            'internalTestPresets' => $request->user()->hasRole(SystemRoles::SYSTEM_SUPERADMIN) ? $presets->presets() : [],
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

        if ((int) $data['modality_code'] === SiatBranchSetting::MODALITY_ELECTRONIC && (bool) ($data['is_active'] ?? false)) {
            return back()->withErrors([
                'modality_code' => 'Electronica en Linea requiere firma XML-DSig con certificado digital. Para pruebas inmediatas del SIN usa Computarizada en Linea.',
            ])->withInput();
        }

        if ((int) $data['environment_code'] === SiatBranchSetting::ENVIRONMENT_PRODUCTION && (bool) ($data['mock_siat'] ?? false)) {
            return back()->withErrors([
                'mock_siat' => 'No puedes activar respuestas simuladas en ambiente Produccion.',
            ])->withInput();
        }

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

    public function applyInternalPreset(Request $request, SiatInternalTestPresetService $presets): RedirectResponse
    {
        abort_unless($request->user()->hasRole(SystemRoles::SYSTEM_SUPERADMIN), 403);

        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'preset' => ['required', 'string', 'max:120'],
        ]);

        $preset = $presets->preset($data['preset']);

        if ($preset === []) {
            return back()->withErrors(['preset' => 'El preset interno SIAT seleccionado no existe.']);
        }

        $setting = SiatBranchSetting::query()->firstOrNew(['branch_id' => $data['branch_id']]);
        $setting->fill(collect($preset)->except(['label', 'token', 'mock_siat'])->all());
        $setting->options = ['mock_siat' => (bool) ($preset['mock_siat'] ?? false)];

        if (filled($preset['token'] ?? null)) {
            $setting->token = $preset['token'];
        }

        $setting->save();
        $this->expireActiveCodes((int) $setting->branch_id);

        return back()->with('success', 'Preset interno SIAT aplicado. Solicita CUIS/CUFD reales o registra codigos manuales de prueba si el caso oficial los provee.');
    }

    public function storeInternalCodes(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole(SystemRoles::SYSTEM_SUPERADMIN), 403);

        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'cuis' => ['nullable', 'string', 'max:120'],
            'cuis_expires_at' => ['nullable', 'date'],
            'cufd' => ['nullable', 'string', 'max:220'],
            'control_code' => ['nullable', 'string', 'max:120'],
            'cufd_valid_until' => ['nullable', 'date'],
            'address' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->expireActiveCodes((int) $data['branch_id']);

        $cuis = null;

        if (filled($data['cuis'] ?? null)) {
            $cuis = SiatCuis::query()->create([
                'branch_id' => $data['branch_id'],
                'code' => $data['cuis'],
                'issued_at' => now(),
                'expires_at' => $data['cuis_expires_at'] ?? now()->addYear(),
                'status' => SiatCuis::STATUS_ACTIVE,
                'response' => ['origen' => 'registro_manual_sistemasuperadmin'],
            ]);
        }

        if (filled($data['cufd'] ?? null)) {
            SiatCufd::query()->create([
                'branch_id' => $data['branch_id'],
                'siat_cuis_id' => $cuis?->id,
                'code' => $data['cufd'],
                'control_code' => $data['control_code'],
                'address' => $data['address'],
                'valid_from' => now(),
                'valid_until' => $data['cufd_valid_until'] ?? now()->addDay(),
                'status' => SiatCufd::STATUS_ACTIVE,
                'response' => ['origen' => 'registro_manual_sistemasuperadmin'],
            ]);
        }

        return back()->with('success', 'Codigos SIAT internos actualizados para pruebas.');
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
