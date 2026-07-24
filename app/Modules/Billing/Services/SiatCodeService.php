<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\SiatBranchSetting;
use App\Modules\Billing\Models\SiatCufd;
use App\Modules\Billing\Models\SiatCuis;

class SiatCodeService
{
    public function __construct(
        private readonly SiatConfigurationService $configuration,
        private readonly SiatSoapClient $soap,
    ) {}

    public function requestCuis(int $branchId): SiatCuis
    {
        $setting = $this->configuration->settingForBranch($branchId);
        $payload = ['SolicitudCuis' => $this->basePayload($setting)];
        $response = $this->soap->call($setting, 'FacturacionCodigos', 'cuis', $payload);
        $body = $response['RespuestaCuis'] ?? $response['RespuestaServicio'] ?? [];

        return SiatCuis::query()->create([
            'branch_id' => $branchId,
            'code' => $body['codigo'] ?? 'CUIS-PENDIENTE',
            'issued_at' => now(),
            'expires_at' => now()->addDays(365),
            'status' => SiatCuis::STATUS_ACTIVE,
            'response' => $response,
        ]);
    }

    public function requestCufd(int $branchId): SiatCufd
    {
        $setting = $this->configuration->settingForBranch($branchId);
        $cuis = $this->configuration->activeCuis($branchId) ?? $this->requestCuis($branchId);
        $payload = ['SolicitudCufd' => [...$this->basePayload($setting), 'cuis' => $cuis->code]];
        $response = $this->soap->call($setting, 'FacturacionCodigos', 'cufd', $payload);
        $body = $response['RespuestaCufd'] ?? $response['RespuestaServicio'] ?? [];

        SiatCufd::query()
            ->where('branch_id', $branchId)
            ->where('status', SiatCufd::STATUS_ACTIVE)
            ->update(['status' => SiatCufd::STATUS_EXPIRED]);

        return SiatCufd::query()->create([
            'branch_id' => $branchId,
            'siat_cuis_id' => $cuis->id,
            'code' => $body['codigo'] ?? 'CUFD-PENDIENTE',
            'control_code' => $body['codigoControl'] ?? null,
            'address' => $body['direccion'] ?? $setting->branch?->address,
            'valid_from' => now(),
            'valid_until' => now()->addDay(),
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
}
