<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\SiatInvoice;
use App\Modules\Billing\Models\SiatPackage;
use App\Modules\Billing\Models\SiatSignificantEvent;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SiatPackageService
{
    public function __construct(
        private readonly SiatConfigurationService $configuration,
        private readonly SiatSoapClient $soap,
        private readonly SiatGzipService $gzip,
        private readonly SiatHashService $hash,
        private readonly SiatXmlValidator $xmlValidator,
    ) {}

    public function buildAndSend(SiatSignificantEvent $event, Collection $invoices): SiatPackage
    {
        $setting = $this->configuration->settingForBranch((int) $event->branch_id);
        $cuis = $this->configuration->activeCuis((int) $event->branch_id);
        $cufd = $this->configuration->requireActiveCufd((int) $event->branch_id);

        if ($invoices->count() > 1000) {
            throw ValidationException::withMessages([
                'billing' => 'Un paquete SIAT no puede superar 1000 facturas. Divide el envio en varios paquetes.',
            ]);
        }

        if (! $cuis) {
            throw ValidationException::withMessages([
                'billing' => 'No existe CUIS activo para enviar el paquete SIAT.',
            ]);
        }

        $invoices->each(function (SiatInvoice $invoice): void {
            $xml = $invoice->signed_xml ?: $invoice->xml;

            if (blank($xml)) {
                throw ValidationException::withMessages([
                    'billing' => "La factura {$invoice->invoice_number} no tiene XML fiscal para empaquetar.",
                ]);
            }

            $this->xmlValidator->validateCompraVenta($xml);
        });

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

        $accepted = filter_var($body['transaccion'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $receptionCode = $body['codigoRecepcion'] ?? null;

        if (! $accepted || ! is_scalar($receptionCode) || blank((string) $receptionCode)) {
            $package->update(['siat_response' => $response]);

            throw ValidationException::withMessages([
                'billing' => 'SIAT no confirmó la recepción del paquete. Revisa la respuesta registrada antes de reenviar.',
            ]);
        }

        $package->update([
            'reception_code' => $body['codigoRecepcion'] ?? null,
            'status' => SiatPackage::STATUS_SENT,
            'siat_response' => $response,
            'sent_at' => now(),
        ]);

        return $package->refresh();
    }

    public function validateReception(SiatPackage $package): SiatPackage
    {
        $setting = $this->configuration->settingForBranch((int) $package->branch_id);
        $cuis = $this->configuration->activeCuis((int) $package->branch_id);
        $cufd = $this->configuration->requireActiveCufd((int) $package->branch_id);

        if (! $cuis || blank($package->reception_code)) {
            throw ValidationException::withMessages([
                'billing' => 'El paquete no tiene CUIS o codigo de recepcion para validar.',
            ]);
        }

        $response = $this->soap->call($setting, 'ServicioFacturacionCompraVenta', 'validacionRecepcionPaqueteFactura', [
            'SolicitudServicioValidacionRecepcionPaquete' => [
                'codigoAmbiente' => $setting->environment_code,
                'codigoDocumentoSector' => $setting->document_sector_code,
                'codigoEmision' => 2,
                'codigoModalidad' => $setting->modality_code,
                'codigoPuntoVenta' => $setting->point_of_sale_code,
                'codigoSistema' => $setting->system_code,
                'codigoSucursal' => $setting->siat_branch_code,
                'cufd' => $cufd->code,
                'cuis' => $cuis->code,
                'nit' => $setting->nit,
                'tipoFacturaDocumento' => $setting->invoice_type_code,
                'codigoRecepcion' => $package->reception_code,
            ],
        ]);

        $body = $response['RespuestaServicioFacturacion'] ?? $response['RespuestaServicio'] ?? [];
        $state = (int) ($body['codigoEstado'] ?? 0);

        return \Illuminate\Support\Facades\DB::transaction(
            function () use ($package, $response, $state, $body): SiatPackage {
                $locked = SiatPackage::query()
                    ->lockForUpdate()
                    ->findOrFail($package->id);

                // Una respuesta tardía no debe degradar una validación ya confirmada.
                if ($locked->status === SiatPackage::STATUS_VALIDATED) {
                    return $locked;
                }

                $validated = $state === 908
                    && filter_var($body['transaccion'] ?? false, FILTER_VALIDATE_BOOLEAN);

                $locked->update([
                    'status' => $validated ? SiatPackage::STATUS_VALIDATED : SiatPackage::STATUS_SENT,
                    'siat_response' => $response,
                    'validated_at' => $validated ? now() : null,
                ]);

                if ($validated) {
                    $locked->invoices()
                        ->orderBy('siat_invoices.id')
                        ->lockForUpdate()
                        ->get()
                        ->each(function (SiatInvoice $invoice): void {
                            if (in_array($invoice->status, [
                                SiatInvoice::STATUS_VOIDED,
                                SiatInvoice::STATUS_VALIDATED,
                            ], true)) {
                                return;
                            }

                            $invoice->update([
                                'status' => SiatInvoice::STATUS_VALIDATED,
                                'validated_at' => now(),
                            ]);
                        });
                }

                return $locked->refresh();
            },
            3,
        );
    }
}
