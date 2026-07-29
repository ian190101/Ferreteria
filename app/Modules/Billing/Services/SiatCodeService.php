<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\SiatBranchSetting;
use App\Modules\Billing\Models\SiatCufd;
use App\Modules\Billing\Models\SiatCuis;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class SiatCodeService
{
    public function __construct(
        private readonly SiatConfigurationService $configuration,
        private readonly SiatSoapClient $soap,
    ) {}

    public function requestCuis(int $branchId): SiatCuis
    {
        $setting = $this->configuration->settingForBranch($branchId);
        $this->assertCanCallSiat($setting);

        $payload = ['SolicitudCuis' => $this->basePayload($setting)];
        $response = $this->soap->call($setting, 'FacturacionCodigos', 'cuis', $payload);
        $body = $response['RespuestaCuis'] ?? $response['RespuestaServicio'] ?? [];
        $this->assertTransaction($body, 'SIAT no acepto la solicitud de CUIS.');

        SiatCuis::query()
            ->where('branch_id', $branchId)
            ->where('status', SiatCuis::STATUS_ACTIVE)
            ->update(['status' => SiatCuis::STATUS_EXPIRED]);

        return SiatCuis::query()->create([
            'branch_id' => $branchId,
            'code' => (string) ($body['codigo'] ?? ''),
            'issued_at' => now(),
            'expires_at' => $this->dateFromBody($body, ['fechaVigencia', 'fechaVencimiento']) ?? now()->addDays(365),
            'status' => SiatCuis::STATUS_ACTIVE,
            'response' => $response,
        ]);
    }

    public function requestCufd(int $branchId): SiatCufd
    {
        $setting = $this->configuration->settingForBranch($branchId);
        $this->assertCanCallSiat($setting);

        $cuis = $this->configuration->activeCuis($branchId) ?? $this->requestCuis($branchId);
        $payload = ['SolicitudCufd' => [...$this->basePayload($setting), 'cuis' => $cuis->code]];
        $response = $this->soap->call($setting, 'FacturacionCodigos', 'cufd', $payload);
        $body = $response['RespuestaCufd'] ?? $response['RespuestaServicio'] ?? [];
        $this->assertTransaction($body, 'SIAT no acepto la solicitud de CUFD.');

        SiatCufd::query()
            ->where('branch_id', $branchId)
            ->where('status', SiatCufd::STATUS_ACTIVE)
            ->update(['status' => SiatCufd::STATUS_EXPIRED]);

        return SiatCufd::query()->create([
            'branch_id' => $branchId,
            'siat_cuis_id' => $cuis->id,
            'code' => (string) ($body['codigo'] ?? ''),
            'control_code' => $body['codigoControl'] ?? null,
            'address' => $body['direccion'] ?? $setting->branch?->address,
            'valid_from' => now(),
            'valid_until' => $this->dateFromBody($body, ['fechaVigencia', 'fechaVencimiento']) ?? now()->addDay(),
            'status' => SiatCufd::STATUS_ACTIVE,
            'response' => $response,
        ]);
    }

    private function basePayload(SiatBranchSetting $setting): array
    {
        return [
            'codigoAmbiente' => $setting->environment_code,
            'codigoModalidad' => $setting->modality_code,
            'codigoPuntoVenta' => $setting->point_of_sale_code,
            'codigoSistema' => $setting->system_code,
            'codigoSucursal' => $setting->siat_branch_code,
            'nit' => $setting->nit,
        ];
    }

    private function assertCanCallSiat(SiatBranchSetting $setting): void
    {
        if (! app()->environment('testing') && ! (bool) ($setting->options['mock_siat'] ?? false) && blank($setting->token)) {
            throw ValidationException::withMessages([
                'billing' => 'No existe token delegado SIAT configurado para esta sucursal.',
            ]);
        }
    }

    private function assertTransaction(array $body, string $message): void
    {
        if (($body['transaccion'] ?? false) === true && filled($body['codigo'] ?? null)) {
            return;
        }

        throw ValidationException::withMessages([
            'billing' => $message.' '.trim((string) ($body['codigoDescripcion'] ?? $body['mensajesList']['descripcion'] ?? 'Revisa el log SIAT para ver el detalle.')),
        ]);
    }

    private function dateFromBody(array $body, array $keys): ?Carbon
    {
        foreach ($keys as $key) {
            if (filled($body[$key] ?? null)) {
                return Carbon::parse($body[$key]);
            }
        }

        return null;
    }
}
