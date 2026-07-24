<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\SiatSignificantEvent;
use Illuminate\Support\Carbon;

class SiatEventService
{
    public function __construct(
        private readonly SiatConfigurationService $configuration,
        private readonly SiatSoapClient $soap,
    ) {}

    public function register(int $branchId, int $eventCode, Carbon $startedAt, Carbon $endedAt, string $description): SiatSignificantEvent
    {
        $setting = $this->configuration->settingForBranch($branchId);
        $cuis = $this->configuration->activeCuis($branchId) ?? app(SiatCodeService::class)->requestCuis($branchId);
        $eventCufd = $this->configuration->requireActiveCufd($branchId);
        $sendCufd = app(SiatCodeService::class)->requestCufd($branchId);

        $payload = [
            'SolicitudEventoSignificativo' => [
                'codigoAmbiente' => $setting->environment_code,
                'codigoMotivoEvento' => $eventCode,
                'codigoPuntoVenta' => $setting->point_of_sale_code,
                'codigoSistema' => $setting->system_code,
                'codigoSucursal' => $setting->siat_branch_code,
                'cufd' => $sendCufd->code,
                'cufdEvento' => $eventCufd->code,
                'cuis' => $cuis->code,
                'descripcion' => $description,
                'fechaHoraInicioEvento' => $startedAt->format('Y-m-d\TH:i:s.v'),
                'fechaHoraFinEvento' => $endedAt->format('Y-m-d\TH:i:s.v'),
                'nit' => $setting->nit,
            ],
        ];

        $response = $this->soap->call($setting, 'FacturacionOperaciones', 'registroEventoSignificativo', $payload);
        $body = $response['RespuestaListaEventos'] ?? $response['RespuestaServicio'] ?? [];

        return SiatSignificantEvent::query()->create([
            'branch_id' => $branchId,
            'siat_cufd_event_id' => $eventCufd->id,
            'siat_cufd_send_id' => $sendCufd->id,
            'event_code' => $eventCode,
            'reception_code' => $body['codigoRecepcionEventoSignificativo'] ?? null,
            'description' => $description,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'status' => SiatSignificantEvent::STATUS_REGISTERED,
            'siat_response' => $response,
        ]);
    }
}
