<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\SiatBranchSetting;
use App\Modules\Billing\Models\SiatCatalogItem;
use Illuminate\Validation\ValidationException;

class SiatCertificationReadinessService
{
    public function __construct(private readonly SiatConfigurationService $configuration) {}

    /**
     * @return array<int, array{key:string,label:string,ok:bool,message:string}>
     */
    public function checklist(int $branchId): array
    {
        try {
            $setting = $this->configuration->settingForBranch($branchId);
        } catch (ValidationException $exception) {
            return [[
                'key' => 'setting',
                'label' => 'Configuracion SIAT',
                'ok' => false,
                'message' => $exception->errors()['billing'][0] ?? 'La sucursal no tiene configuracion SIAT activa.',
            ]];
        }

        return [
            $this->check('token', 'Token delegado', filled($setting->token_encrypted), 'Debe guardar el token delegado del ambiente correspondiente.'),
            $this->check('modality', 'Modalidad soportada', $setting->modality_code === SiatBranchSetting::MODALITY_COMPUTERIZED, 'Para estas pruebas usa Computarizada en Linea. Electronica requiere XML-DSig y certificado digital.'),
            $this->check('system_code', 'Codigo sistema', filled($setting->system_code), 'Debe existir el codigo de sistema asignado por SIAT.'),
            $this->check('nit', 'NIT emisor', preg_match('/^\d{5,20}$/', (string) $setting->nit) === 1, 'El NIT debe contener solo numeros.'),
            $this->check('activity', 'Actividad economica base', filled($setting->economic_activity_code), 'Configura una actividad economica base o homologa todos los productos.'),
            $this->check('sin_product', 'Producto SIN base', filled($setting->sin_product_code), 'Configura un producto SIN base o homologa todos los productos.'),
            $this->check('cuis', 'CUIS vigente', (bool) $this->configuration->activeCuis($branchId), 'Solicita CUIS antes de facturar.'),
            $this->check('cufd', 'CUFD vigente', (bool) $this->configuration->activeCufd($branchId), 'Solicita CUFD vigente antes de facturar.'),
            $this->check('catalogs', 'Catalogos SIAT', $this->hasCoreCatalogs(), 'Sincroniza catalogos oficiales antes de emitir facturas.'),
            $this->check('xsd', 'XSD local', is_file(base_path('resources/siat/xsd/facturaComputarizadaCompraVenta.xsd')), 'Falta el esquema XSD local de Factura Compra Venta.'),
        ];
    }

    public function assertReadyForInvoice(int $branchId): SiatBranchSetting
    {
        $setting = $this->configuration->settingForBranch($branchId);
        $allowMock = app()->environment('testing') || (bool) ($setting->options['mock_siat'] ?? false);
        $failed = collect($this->checklist($branchId))
            ->reject(fn (array $check): bool => $allowMock && in_array($check['key'], ['catalogs'], true))
            ->firstWhere('ok', false);

        if ($failed) {
            throw ValidationException::withMessages([
                'billing' => "Facturacion SIAT incompleta: {$failed['label']}. {$failed['message']}",
            ]);
        }

        return $setting;
    }

    private function hasCoreCatalogs(): bool
    {
        $required = [
            'identity_document_types',
            'payment_methods',
            'unit_measures',
            'void_reasons',
            'significant_events',
            'legends',
            'document_sectors',
        ];

        return collect($required)->every(fn (string $type): bool => SiatCatalogItem::query()
            ->where('catalog_type', $type)
            ->where('is_active', true)
            ->exists());
    }

    private function check(string $key, string $label, bool $ok, string $message): array
    {
        return compact('key', 'label', 'ok', 'message');
    }
}
