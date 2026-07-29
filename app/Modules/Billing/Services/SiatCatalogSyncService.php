<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\SiatCatalogItem;
use Illuminate\Validation\ValidationException;

class SiatCatalogSyncService
{
    public function __construct(
        private readonly SiatConfigurationService $configuration,
        private readonly SiatSoapClient $soap,
    ) {}

    public function syncCoreCatalogs(int $branchId): array
    {
        $setting = $this->configuration->settingForBranch($branchId);
        $cuis = $this->configuration->activeCuis($branchId) ?? app(SiatCodeService::class)->requestCuis($branchId);
        $allowFallback = app()->environment('testing') || (bool) ($setting->options['mock_siat'] ?? false);

        $catalogs = [
            'identity_document_types' => ['method' => 'sincronizarParametricaTipoDocumentoIdentidad', 'fallback' => ['1' => 'CI - CEDULA DE IDENTIDAD', '5' => 'NIT']],
            'payment_methods' => ['method' => 'sincronizarParametricaTipoMetodoPago', 'fallback' => ['1' => 'EFECTIVO', '2' => 'TARJETA', '4' => 'TRANSFERENCIA', '7' => 'QR']],
            'unit_measures' => ['method' => 'sincronizarParametricaUnidadMedida', 'fallback' => ['58' => 'UNIDAD', '47' => 'KILOGRAMO', '53' => 'METRO']],
            'void_reasons' => ['method' => 'sincronizarParametricaMotivoAnulacion', 'fallback' => ['1' => 'FACTURA MAL EMITIDA']],
            'significant_events' => ['method' => 'sincronizarParametricaEventosSignificativos', 'fallback' => ['1' => 'CORTE DEL SERVICIO DE INTERNET']],
            'legends' => ['method' => 'sincronizarListaLeyendasFactura', 'fallback' => ['1' => 'Ley N 453: Tienes derecho a recibir informacion.']],
            'document_sectors' => ['method' => 'sincronizarParametricaTipoDocumentoSector', 'fallback' => ['1' => 'FACTURA COMPRA VENTA']],
        ];

        $synced = [];

        foreach ($catalogs as $type => $definition) {
            try {
                $response = $this->soap->call($setting, 'FacturacionSincronizacion', $definition['method'], [
                    'SolicitudSincronizacion' => [
                        'codigoAmbiente' => $setting->environment_code,
                        'codigoPuntoVenta' => $setting->point_of_sale_code,
                        'codigoSistema' => $setting->system_code,
                        'codigoSucursal' => $setting->siat_branch_code,
                        'cuis' => $cuis->code,
                        'nit' => $setting->nit,
                    ],
                ]);

                $items = $this->extractItems($response);
            } catch (\Throwable) {
                if (! $allowFallback) {
                    throw ValidationException::withMessages([
                        'billing' => "No se pudo sincronizar el catalogo {$type} desde SIAT. Corrige la conexion/token antes de emitir facturas.",
                    ]);
                }

                $items = collect($definition['fallback'])->map(fn ($description, $code) => ['codigoClasificador' => $code, 'descripcion' => $description])->values()->all();
            }

            if (! $allowFallback && count($items) === 0) {
                throw ValidationException::withMessages([
                    'billing' => "SIAT respondio el catalogo {$type}, pero no se detectaron items validos.",
                ]);
            }

            foreach ($items as $item) {
                $code = (string) ($item['codigoClasificador'] ?? $item['codigoActividad'] ?? $item['codigoProducto'] ?? $item['codigo'] ?? '');
                $description = (string) ($item['descripcion'] ?? $item['descripcionProducto'] ?? $item['leyenda'] ?? 'Sin descripcion');

                if ($code === '') {
                    continue;
                }

                SiatCatalogItem::query()->updateOrCreate(
                    ['catalog_type' => $type, 'code' => $code],
                    ['description' => $description, 'payload' => $item, 'is_active' => true, 'synced_at' => now()],
                );
            }

            $synced[$type] = count($items);
        }

        return $synced;
    }

    private function extractItems(array $response): array
    {
        $encoded = json_decode(json_encode($response), true) ?: [];
        $flat = collect($encoded)->flatten(4)->all();

        return collect($flat)
            ->filter(fn ($item) => is_array($item) && (isset($item['codigoClasificador']) || isset($item['codigoActividad']) || isset($item['codigoProducto']) || isset($item['codigo'])))
            ->values()
            ->all();
    }
}
