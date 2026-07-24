<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\SiatInvoice;
use App\Modules\Billing\Models\SiatPackage;
use App\Modules\Billing\Models\SiatSignificantEvent;
use Illuminate\Support\Collection;

class SiatPackageService
{
    public function __construct(
        private readonly SiatConfigurationService $configuration,
        private readonly SiatSoapClient $soap,
        private readonly SiatGzipService $gzip,
        private readonly SiatHashService $hash,
    ) {}

    public function buildAndSend(SiatSignificantEvent $event, Collection $invoices): SiatPackage
    {
        $setting = $this->configuration->settingForBranch((int) $event->branch_id);
        $cuis = $this->configuration->activeCuis((int) $event->branch_id);
        $cufd = $this->configuration->requireActiveCufd((int) $event->branch_id);
        $xmlBundle = $invoices->map(fn (SiatInvoice $invoice) => $invoice->signed_xml ?: $invoice->xml)->implode("\n");
        $compressed = $this->gzip->compress($xmlBundle);
        $hash = $this->hash->sha256($compressed);

        $package = SiatPackage::query()->create([
            'branch_id' => $event->branch_id,
            'siat_significant_event_id' => $event->id,
            'invoice_count' => $invoices->count(),
            'hash' => $hash,
            'gzip_base64' => base64_encode($compressed),
            'status' => SiatPackage::STATUS_PENDING,
        ]);

        $package->invoices()->sync($invoices->values()->mapWithKeys(fn ($invoice, $index) => [$invoice->id => ['file_number' => $index + 1]])->all());

        $payload = [
            'SolicitudServicioRecepcionPaquete' => [
                'codigoAmbiente' => $setting->environment_code,
                'codigoDocumentoSector' => $setting->document_sector_code,
                'codigoEmision' => 2,
                'codigoModalidad' => $setting->modality_code,
                'codigoPuntoVenta' => $setting->point_of_sale_code,
                'codigoSistema' => $setting->system_code,
                'codigoSucursal' => $setting->siat_branch_code,
                'cufd' => $cufd->code,
                'cuis' => $cuis?->code,
                'nit' => $setting->nit,
                'tipoFacturaDocumento' => $setting->invoice_type_code,
                'archivo' => $package->gzip_base64,
                'fechaEnvio' => now()->format('Y-m-d\TH:i:s.v'),
                'hashArchivo' => $hash,
                'cantidadFacturas' => $invoices->count(),
                'codigoEvento' => $event->reception_code,
            ],
        ];

        $response = $this->soap->call($setting, 'ServicioFacturacionCompraVenta', 'recepcionPaqueteFactura', $payload);
        $body = $response['RespuestaServicioFacturacion'] ?? $response['RespuestaServicio'] ?? [];

        $package->update([
            'reception_code' => $body['codigoRecepcion'] ?? null,
            'status' => SiatPackage::STATUS_SENT,
            'siat_response' => $response,
            'sent_at' => now(),
        ]);

        return $package->refresh();
    }
}
