<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\SiatBranchSetting;
use App\Modules\Billing\Models\SiatInvoice;
use App\Modules\Billing\Models\SiatInvoiceItem;
use App\Modules\Sales\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SiatInvoiceService
{
    public function __construct(
        private readonly BillingWorkflowPolicy $workflow,
        private readonly SiatConfigurationService $configuration,
        private readonly SiatCufGenerator $cufGenerator,
        private readonly SiatInvoiceXmlBuilder $xmlBuilder,
        private readonly SiatXmlValidator $xmlValidator,
        private readonly SiatXmlSigner $xmlSigner,
        private readonly SiatGzipService $gzip,
        private readonly SiatHashService $hash,
        private readonly SiatSoapClient $soap,
    ) {}

    public function issueFromSale(Sale $sale, int $userId, bool $temporaryWhenOffline = false): SiatInvoice
    {
        if (! $this->workflow->canIssueForSale($sale)) {
            throw ValidationException::withMessages([
                'billing' => 'El perfil de negocio actual no permite emitir factura SIAT para este documento.',
            ]);
        }

        return DB::transaction(function () use ($sale, $userId, $temporaryWhenOffline) {
            $sale = Sale::query()
                ->with([
                    'branch:id,name,address,phone',
                    'user:id,name,email',
                    'currency:id,code,exchange_rate_to_bob',
                    'payments.method:id,code,name',
                    'items.product:id,name,sku,barcode,product_unit_id',
                    'items.product.siatMapping',
                ])
                ->lockForUpdate()
                ->findOrFail($sale->id);

            $existing = SiatInvoice::query()
                ->where('sale_id', $sale->id)
                ->whereIn('status', [SiatInvoice::STATUS_PENDING, SiatInvoice::STATUS_VALIDATED])
                ->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'billing' => 'Esta nota de venta ya tiene una factura fiscal generada o validada.',
                ]);
            }

            if ($this->workflow->requireCustomerTaxData() && blank($sale->customer_document)) {
                throw ValidationException::withMessages([
                    'billing' => 'La configuracion fiscal exige documento/NIT del cliente antes de emitir factura.',
                ]);
            }

            $setting = $this->configuration->settingForBranch((int) $sale->branch_id);
            $cufd = $this->configuration->activeCufd((int) $sale->branch_id);

            if (! $cufd && $temporaryWhenOffline) {
                return $this->createTemporaryInvoice($sale, $setting, $userId);
            }

            if (! $cufd && ! $this->workflow->autoRequestCufd()) {
                throw ValidationException::withMessages([
                    'billing' => 'No existe CUFD vigente y la configuracion fiscal no permite solicitarlo automaticamente.',
                ]);
            }

            $cufd ??= app(SiatCodeService::class)->requestCufd((int) $sale->branch_id);
            $issuedAt = now();
            $invoiceNumber = $this->nextInvoiceNumber((int) $sale->branch_id);
            $cuf = $this->cufGenerator->generate($setting, $invoiceNumber, $issuedAt, (string) $cufd->control_code);
            $paymentMethodCode = $this->paymentMethodCode($sale);

            $invoice = SiatInvoice::query()->create([
                'sale_id' => $sale->id,
                'branch_id' => $sale->branch_id,
                'user_id' => $userId,
                'siat_cufd_id' => $cufd->id,
                'invoice_number' => (string) $invoiceNumber,
                'cuf' => $cuf,
                'cufd' => $cufd->code,
                'environment_code' => $setting->environment_code,
                'modality_code' => $setting->modality_code,
                'emission_type_code' => 1,
                'invoice_type_code' => $setting->invoice_type_code,
                'document_sector_code' => $setting->document_sector_code,
                'siat_branch_code' => $setting->siat_branch_code,
                'point_of_sale_code' => $setting->point_of_sale_code,
                'issued_at' => $issuedAt,
                'customer_name' => $sale->customer_name ?: 'Sin nombre',
                'identity_document_type_code' => $this->identityDocumentType($sale->customer_document),
                'customer_document' => $this->customerDocument($sale->customer_document),
                'customer_complement' => null,
                'customer_code' => $sale->customer_id ? "CLI-{$sale->customer_id}" : $this->customerDocument($sale->customer_document),
                'payment_method_code' => $paymentMethodCode,
                'total_amount' => $sale->total,
                'taxable_amount' => $sale->total,
                'additional_discount' => $sale->discount_total > 0 ? $sale->discount_total : null,
                'exception_code' => $this->exceptionCode($sale->customer_document),
                'currency_code' => $sale->currency?->code === 'USD' ? 2 : 1,
                'exchange_rate' => $sale->exchange_rate_to_bob ?: 1,
                'total_amount_currency' => $sale->total,
                'legend' => $this->legend(),
                'operator_username' => str($sale->user?->name ?? 'usuario')->ascii()->replace(' ', '')->limit(40, '')->toString(),
                'status' => SiatInvoice::STATUS_DRAFT,
            ]);

            $invoice->items()->createMany($this->invoiceItems($sale, $setting));
            $invoice->load(['items', 'branch.siatSetting', 'cufd']);

            $xml = $this->xmlBuilder->buildCompraVenta($invoice);
            $this->xmlValidator->validateWellFormed($xml);
            $signedXml = $this->xmlSigner->signIfRequired($xml, $setting);
            $compressed = $this->gzip->compress($signedXml);
            $gzipBase64 = base64_encode($compressed);
            $gzipHash = $this->hash->sha256($compressed);

            $invoice->update([
                'xml' => $xml,
                'signed_xml' => $signedXml,
                'xml_hash' => $this->hash->sha256($signedXml),
                'gzip_base64' => $gzipBase64,
                'gzip_hash' => $gzipHash,
                'status' => SiatInvoice::STATUS_PENDING,
            ]);

            return $this->send($invoice->refresh(), $setting);
        });
    }

    public function send(SiatInvoice $invoice, ?SiatBranchSetting $setting = null): SiatInvoice
    {
        $setting ??= $this->configuration->settingForBranch((int) $invoice->branch_id);
        $cuis = $this->configuration->activeCuis((int) $invoice->branch_id);

        if (! $cuis) {
            throw ValidationException::withMessages([
                'billing' => 'No existe CUIS activo para enviar la factura.',
            ]);
        }

        $payload = [
            'SolicitudServicioRecepcionFactura' => [
                'codigoAmbiente' => $invoice->environment_code,
                'codigoDocumentoSector' => $invoice->document_sector_code,
                'codigoEmision' => $invoice->emission_type_code,
                'codigoModalidad' => $invoice->modality_code,
                'codigoPuntoVenta' => $invoice->point_of_sale_code,
                'codigoSistema' => $setting->system_code,
                'codigoSucursal' => $invoice->siat_branch_code,
                'cufd' => $invoice->cufd,
                'cuis' => $cuis->code,
                'nit' => $setting->nit,
                'tipoFacturaDocumento' => $invoice->invoice_type_code,
                'archivo' => $invoice->gzip_base64,
                'fechaEnvio' => now()->format('Y-m-d\TH:i:s.v'),
                'hashArchivo' => $invoice->gzip_hash,
            ],
        ];

        $response = $this->soap->call($setting, 'ServicioFacturacionCompraVenta', 'recepcionFactura', $payload);
        $body = $response['RespuestaServicioFacturacion'] ?? $response['RespuestaServicio'] ?? [];
        $state = (int) ($body['codigoEstado'] ?? 0);

        $invoice->update([
            'reception_code' => $body['codigoRecepcion'] ?? $invoice->reception_code,
            'siat_state_code' => $state ?: null,
            'status' => $state === 908 ? SiatInvoice::STATUS_VALIDATED : ($state === 904 ? SiatInvoice::STATUS_OBSERVED : SiatInvoice::STATUS_PENDING),
            'siat_response' => $response,
            'observations' => $body['codigosRespuestas'] ?? null,
            'sent_at' => now(),
            'validated_at' => $state === 908 ? now() : null,
        ]);

        return $invoice->refresh();
    }

    public function void(SiatInvoice $invoice, int $reasonCode, string $reason): SiatInvoice
    {
        $setting = $this->configuration->settingForBranch((int) $invoice->branch_id);
        $cuis = $this->configuration->activeCuis((int) $invoice->branch_id);

        if (! $cuis) {
            throw ValidationException::withMessages(['billing' => 'No existe CUIS activo para anular la factura.']);
        }

        $payload = [
            'SolicitudServicioAnulacionFactura' => [
                'codigoAmbiente' => $invoice->environment_code,
                'codigoDocumentoSector' => $invoice->document_sector_code,
                'codigoEmision' => $invoice->emission_type_code,
                'codigoModalidad' => $invoice->modality_code,
                'codigoPuntoVenta' => $invoice->point_of_sale_code,
                'codigoSistema' => $setting->system_code,
                'codigoSucursal' => $invoice->siat_branch_code,
                'cufd' => $invoice->cufd,
                'cuis' => $cuis->code,
                'nit' => $setting->nit,
                'tipoFacturaDocumento' => $invoice->invoice_type_code,
                'codigoMotivo' => $reasonCode,
                'cuf' => $invoice->cuf,
            ],
        ];

        $response = $this->soap->call($setting, 'ServicioFacturacionCompraVenta', 'anulacionFactura', $payload);

        $invoice->update([
            'status' => SiatInvoice::STATUS_VOIDED,
            'voided_at' => now(),
            'void_reason_code' => $reasonCode,
            'void_reason' => $reason,
            'siat_response' => $response,
        ]);

        return $invoice->refresh();
    }

    private function createTemporaryInvoice(Sale $sale, SiatBranchSetting $setting, int $userId): SiatInvoice
    {
        return SiatInvoice::query()->create([
            'sale_id' => $sale->id,
            'branch_id' => $sale->branch_id,
            'user_id' => $userId,
            'invoice_number' => (string) $this->nextInvoiceNumber((int) $sale->branch_id),
            'environment_code' => $setting->environment_code,
            'modality_code' => $setting->modality_code,
            'document_sector_code' => $setting->document_sector_code,
            'siat_branch_code' => $setting->siat_branch_code,
            'point_of_sale_code' => $setting->point_of_sale_code,
            'issued_at' => now(),
            'customer_name' => $sale->customer_name ?: 'Sin nombre',
            'customer_document' => $this->customerDocument($sale->customer_document),
            'customer_code' => $sale->customer_id ? "CLI-{$sale->customer_id}" : $this->customerDocument($sale->customer_document),
            'payment_method_code' => $this->paymentMethodCode($sale),
            'total_amount' => $sale->total,
            'taxable_amount' => $sale->total,
            'currency_code' => $sale->currency?->code === 'USD' ? 2 : 1,
            'exchange_rate' => $sale->exchange_rate_to_bob ?: 1,
            'total_amount_currency' => $sale->total,
            'legend' => $this->legend(),
            'operator_username' => str($sale->user?->name ?? 'usuario')->ascii()->replace(' ', '')->limit(40, '')->toString(),
            'status' => SiatInvoice::STATUS_TEMPORARY,
        ]);
    }

    private function invoiceItems(Sale $sale, SiatBranchSetting $setting): array
    {
        return $sale->items->map(function ($item) use ($setting) {
            $mapping = $item->product?->siatMapping;

            if ((! $mapping || ! $mapping->is_invoiceable) && $this->workflow->requiresProductMapping()) {
                throw ValidationException::withMessages([
                    'billing' => "El producto {$item->description} no esta homologado para facturacion SIAT.",
                ]);
            }

            $quantity = max((float) ($item->display_quantity ?: $item->meters), 0.0001);
            $subtotal = (float) $item->total;
            $unitPrice = $quantity > 0 ? round($subtotal / $quantity, 4) : (float) $item->unit_price;

            return [
                'sale_item_id' => $item->id,
                'economic_activity_code' => $mapping?->economic_activity_code ?: $setting->economic_activity_code,
                'sin_product_code' => $mapping?->sin_product_code ?: $setting->sin_product_code,
                'product_code' => $item->product?->sku ?: $item->product?->barcode ?: "PROD-{$item->product_id}",
                'description' => $mapping?->fiscal_description ?: $item->description,
                'quantity' => $quantity,
                'unit_measure_code' => $mapping?->unit_measure_code ?: 58,
                'unit_price' => $unitPrice,
                'discount_amount' => (float) $item->discount_amount > 0 ? $item->discount_amount : null,
                'subtotal' => $subtotal,
                'serial_number' => null,
                'imei_number' => null,
            ];
        })->all();
    }

    private function nextInvoiceNumber(int $branchId): int
    {
        $last = SiatInvoice::query()
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->selectRaw('MAX(CAST(invoice_number AS UNSIGNED)) as max_number')
            ->value('max_number');

        return ((int) $last) + 1;
    }

    private function paymentMethodCode(Sale $sale): int
    {
        $code = $sale->payments->first()?->method?->code;

        return [
            'cash' => 1,
            'card' => 2,
            'transfer' => 4,
            'qr' => 7,
        ][$code] ?? 1;
    }

    private function identityDocumentType(?string $document): int
    {
        return preg_match('/^\d+$/', (string) $document) ? 1 : 5;
    }

    private function customerDocument(?string $document): string
    {
        $value = trim((string) $document);

        return $value !== '' ? $value : '99003';
    }

    private function exceptionCode(?string $document): int
    {
        return in_array($this->customerDocument($document), ['99001', '99002', '99003'], true) ? 1 : 0;
    }

    private function legend(): string
    {
        return 'Ley N 453: Tienes derecho a recibir informacion sobre las caracteristicas y contenidos de los productos que adquieras.';
    }
}
