<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branches\Models\Branch;
use App\Modules\Settings\Models\ApplicationLicense;
use App\Modules\Settings\Models\ImportBatch;
use App\Modules\Settings\Models\MaintenanceBackup;
use App\Modules\Settings\Models\MaintenanceLog;
use App\Modules\Settings\Models\SystemSetting;
use App\Modules\Settings\Services\BulkImportService;
use App\Modules\Settings\Services\ClientSetupService;
use App\Modules\Settings\Services\LicenseService;
use App\Modules\Settings\Services\MaintenanceBackupService;
use App\Modules\Settings\Services\ProductionAlertService;
use App\Modules\Settings\Services\ProductionLogService;
use App\Modules\Settings\Services\ProductionUpdateService;
use App\Modules\Settings\Services\SystemHealthService;
use App\Support\SystemRoles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SystemController extends Controller
{
    public function index(SystemHealthService $health, ProductionUpdateService $updates): Response
    {
        return Inertia::render('Settings/System/Index', [
            'backups' => MaintenanceBackup::query()
                ->with('user:id,name')
                ->latest()
                ->paginate(10)
                ->withQueryString(),
            'logs' => MaintenanceLog::query()
                ->with('user:id,name')
                ->latest()
                ->limit(20)
                ->get(),
            'imports' => ImportBatch::query()
                ->with('user:id,name')
                ->latest()
                ->limit(10)
                ->get(),
            'licenses' => $this->isSystemSuperadmin(request())
                ? ApplicationLicense::query()->latest()->limit(10)->get()
                : [],
            'branches' => Branch::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'productionSettings' => $this->productionSettings(),
            'finePermissionSettings' => $this->finePermissionSettings(),
            'health' => $health->check(),
            'updateStatus' => [
                'pending_migrations' => $updates->pendingMigrations(),
                'latest_pre_update_backup' => $updates->latestPreUpdateBackup(),
                'version' => config('system_info.version'),
            ],
            'isSystemSuperadmin' => $this->isSystemSuperadmin(request()),
        ]);
    }

    public function backup(Request $request, MaintenanceBackupService $backups): RedirectResponse
    {
        abort_unless($request->user()?->can('settings.manage'), 403);

        $validated = $request->validate([
            'format' => ['nullable', 'in:json,sql'],
        ]);
        $format = $validated['format'] ?? 'json';
        $backup = $backups->generate($format, $request->user());

        app(ProductionLogService::class)->record('maintenance', 'info', 'backup_created', 'Backup manual generado.', [
            'backup_id' => $backup->id,
            'format' => $format,
        ]);

        return redirect()->route('settings.system.index')->with('success', 'Backup '.$format.' generado correctamente.');
    }

    public function downloadBackup(Request $request, MaintenanceBackup $backup): BinaryFileResponse
    {
        abort_unless($request->user()?->can('settings.manage'), 403);

        $path = Storage::disk($backup->disk)->path($backup->path);

        abort_unless(is_file($path), 404);

        return response()->download($path, basename($backup->path), [
            'Content-Type' => str_ends_with($backup->path, '.sql')
                ? 'application/sql; charset=UTF-8'
                : 'application/json; charset=UTF-8',
        ]);
    }

    public function verifyBackup(Request $request, MaintenanceBackup $backup, MaintenanceBackupService $backups): RedirectResponse
    {
        abort_unless($request->user()?->can('settings.manage'), 403);

        $result = $backups->verifyReadable($backup);
        $backup->update([
            'status' => $result['ok'] ? 'verified' : 'failed_verification',
            'metadata' => array_merge($backup->metadata ?? [], ['verification' => $result]),
        ]);

        app(ProductionLogService::class)->record('maintenance', $result['ok'] ? 'info' : 'warning', 'backup_verified', $result['message'], [
            'backup_id' => $backup->id,
        ]);

        return redirect()
            ->route('settings.system.index')
            ->with($result['ok'] ? 'success' : 'warning', $result['message']);
    }

    public function restoreBackup(Request $request, MaintenanceBackup $backup, ProductionUpdateService $updates): RedirectResponse
    {
        abort_unless($this->isSystemSuperadmin($request) || $request->user()?->can('production.rollback.manage'), 403);

        $validated = $request->validate([
            'confirmation' => ['required', 'in:RESTAURAR'],
        ]);

        $result = $updates->rollbackToBackup($backup);

        return redirect()
            ->route('settings.system.index')
            ->with($result['ok'] ? 'success' : 'warning', $result['message']);
    }

    public function updateProductionSettings(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('settings.manage'), 403);

        $validated = $request->validate([
            'backup_frequency' => ['required', 'in:daily,weekly,disabled'],
            'backup_format' => ['required', 'in:json,sql'],
            'backup_retention_days' => ['required', 'integer', 'min:1', 'max:365'],
            'siat_auto_cufd' => ['required', 'boolean'],
            'siat_auto_catalog_sync' => ['required', 'boolean'],
            'technical_alert_email' => ['nullable', 'email', 'max:160'],
            'offline_pos_enabled' => ['required', 'boolean'],
            'license_validation_mode' => ['required', 'in:offline,online,hybrid'],
        ]);

        SystemSetting::query()->updateOrCreate(
            ['key' => 'production.maintenance'],
            [
                'group' => 'production',
                'value' => $validated,
                'description' => 'Configuracion operativa de produccion: backups, SIAT, alertas y contingencia.',
                'is_public' => false,
            ],
        );

        app(ProductionLogService::class)->record('maintenance', 'info', 'production_settings_updated', 'Configuracion productiva actualizada.', $validated);

        return redirect()->route('settings.system.index')->with('success', 'Configuracion productiva actualizada.');
    }

    public function storeLicense(Request $request, LicenseService $licenses): RedirectResponse
    {
        abort_unless($this->isSystemSuperadmin($request), 403);

        $validated = $request->validate([
            'holder_name' => ['required', 'string', 'max:160'],
            'nit' => ['nullable', 'string', 'max:30'],
            'domain' => ['nullable', 'string', 'max:160'],
            'license_key' => ['nullable', 'string', 'max:160'],
            'support_until' => ['nullable', 'date'],
            'allowed_modules' => ['nullable', 'array'],
            'allowed_modules.*' => ['string', 'max:80'],
            'activation_mode' => ['required', 'in:offline,online'],
            'status' => ['required', 'in:active,suspended,expired'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $license = $licenses->createOrUpdate($validated);

        app(ProductionLogService::class)->record('maintenance', 'info', 'license_saved', 'Licencia de instalacion actualizada.', [
            'license_id' => $license->id,
            'holder_name' => $license->holder_name,
            'nit' => $license->nit,
            'domain' => $license->domain,
        ]);

        return redirect()->route('settings.system.index')->with('success', 'Licencia guardada correctamente.');
    }

    public function import(Request $request, BulkImportService $imports): RedirectResponse
    {
        abort_unless($request->user()?->can('settings.manage'), 403);

        $validated = $request->validate([
            'module' => ['required', 'in:products,customers,suppliers'],
            'branch_id' => ['required', 'exists:branches,id'],
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,pdf', 'max:10240'],
        ]);

        abort_unless(in_array((int) $validated['branch_id'], app(\App\Support\BranchAccess::class)->idsFor($request->user()), true), 403);

        $batch = $imports->import($validated['file'], $validated['module'], (int) $validated['branch_id'], $request->user()?->id);

        return redirect()->route('settings.system.index')->with(
            $batch->failed_rows > 0 ? 'warning' : 'success',
            "Importacion finalizada: {$batch->created_rows} creados, {$batch->updated_rows} actualizados, {$batch->failed_rows} con error.",
        );
    }

    public function runSetupWizard(Request $request, ClientSetupService $setup): RedirectResponse
    {
        abort_unless($this->isSystemSuperadmin($request) || $request->user()?->can('setup.wizard.manage'), 403);

        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:160'],
            'branch_name' => ['required', 'string', 'max:160'],
            'branch_code' => ['required', 'string', 'max:30'],
            'branch_address' => ['nullable', 'string', 'max:255'],
            'branch_phone' => ['nullable', 'string', 'max:80'],
            'primary_color' => ['required', 'string', 'max:20'],
            'secondary_color' => ['required', 'string', 'max:20'],
            'admin_name' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'email', 'max:160'],
            'admin_password' => ['required', 'string', 'min:8', 'max:120'],
        ]);

        $setup->setup($validated);

        return redirect()->route('settings.system.index')->with('success', 'Asistente inicial aplicado correctamente.');
    }

    public function updateFinePermissions(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('settings.manage'), 403);

        $validated = $request->validate([
            'cost_visibility_requires_permission' => ['required', 'boolean'],
            'void_sales_requires_permission' => ['required', 'boolean'],
            'void_payments_requires_permission' => ['required', 'boolean'],
            'sensitive_exports_requires_permission' => ['required', 'boolean'],
            'max_discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        SystemSetting::query()->updateOrCreate(
            ['key' => 'security.fine_permissions'],
            [
                'group' => 'security',
                'value' => $validated,
                'description' => 'Politicas finas de permisos para costos, anulaciones, exportaciones sensibles y descuentos.',
                'is_public' => false,
            ],
        );

        app(ProductionLogService::class)->record('maintenance', 'info', 'fine_permissions_updated', 'Politicas finas de permisos actualizadas.', $validated);

        return redirect()->route('settings.system.index')->with('success', 'Permisos finos actualizados.');
    }

    public function testAlert(Request $request, ProductionAlertService $alerts): RedirectResponse
    {
        abort_unless($request->user()?->can('production.alerts.manage') || $request->user()?->can('settings.manage'), 403);

        $sent = $alerts->notify('Prueba de alerta productiva', 'Esta es una prueba enviada desde el centro de produccion.', [
            'user_id' => $request->user()?->id,
            'app_url' => config('app.url'),
        ]);

        return redirect()
            ->route('settings.system.index')
            ->with($sent ? 'success' : 'warning', $sent ? 'Alerta de prueba enviada.' : 'No se pudo enviar la alerta. Revisa el correo tecnico y configuracion MAIL.');
    }

    public function prepareUpdate(Request $request, ProductionUpdateService $updates): RedirectResponse
    {
        abort_unless($this->isSystemSuperadmin($request), 403);

        $validated = $request->validate([
            'target_version' => ['nullable', 'string', 'max:40'],
        ]);

        $result = $updates->prepare($validated['target_version'] ?? null);

        return redirect()->route('settings.system.index')->with(
            'success',
            'Actualizacion preparada con backup '.$result['backup']->path.'.',
        );
    }

    public function applyMigrations(Request $request, ProductionUpdateService $updates): RedirectResponse
    {
        abort_unless($this->isSystemSuperadmin($request), 403);

        $updates->applyMigrations();

        return redirect()->route('settings.system.index')->with('success', 'Migraciones aplicadas correctamente.');
    }

    private function productionSettings(): array
    {
        $stored = SystemSetting::query()
            ->where('key', 'production.maintenance')
            ->value('value');

        return array_merge([
            'backup_frequency' => 'daily',
            'backup_format' => 'sql',
            'backup_retention_days' => 14,
            'siat_auto_cufd' => true,
            'siat_auto_catalog_sync' => true,
            'technical_alert_email' => env('TECHNICAL_ALERT_EMAIL'),
            'offline_pos_enabled' => false,
            'license_validation_mode' => 'offline',
        ], is_array($stored) ? $stored : []);
    }

    private function finePermissionSettings(): array
    {
        $stored = SystemSetting::query()
            ->where('key', 'security.fine_permissions')
            ->value('value');

        return array_merge([
            'cost_visibility_requires_permission' => true,
            'void_sales_requires_permission' => true,
            'void_payments_requires_permission' => true,
            'sensitive_exports_requires_permission' => true,
            'max_discount_percent' => 0,
        ], is_array($stored) ? $stored : []);
    }

    private function isSystemSuperadmin(Request $request): bool
    {
        return (bool) $request->user()?->hasRole(SystemRoles::SYSTEM_SUPERADMIN);
    }
}
