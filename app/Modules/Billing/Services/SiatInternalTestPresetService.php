<?php

namespace App\Modules\Billing\Services;

class SiatInternalTestPresetService
{
    /**
     * Datos base para pruebas internas en ambiente piloto. Los valores fiscales sensibles
     * deben reemplazarse por los entregados en los casos de prueba oficiales del SIN.
     *
     * @return array<string, array<string, mixed>>
     */
    public function presets(): array
    {
        return [
            'piloto_computarizada_compra_venta' => [
                'label' => 'Piloto SIAT - Computarizada compra venta',
                'nit' => env('SIAT_TEST_NIT', '123456789'),
                'business_name' => env('SIAT_TEST_BUSINESS_NAME', 'EMPRESA PILOTO SIAT'),
                'municipality' => env('SIAT_TEST_MUNICIPALITY', 'Santa Cruz'),
                'phone' => env('SIAT_TEST_PHONE', '70000000'),
                'system_code' => env('SIAT_TEST_SYSTEM_CODE', 'CODIGO-SISTEMA-PILOTO'),
                'environment_code' => 2,
                'modality_code' => 2,
                'emission_type_code' => 1,
                'invoice_type_code' => 1,
                'document_sector_code' => 1,
                'economic_activity_code' => env('SIAT_TEST_ECONOMIC_ACTIVITY_CODE', 471100),
                'sin_product_code' => env('SIAT_TEST_SIN_PRODUCT_CODE', 99100),
                'siat_branch_code' => env('SIAT_TEST_BRANCH_CODE', 0),
                'point_of_sale_code' => env('SIAT_TEST_POS_CODE', 0),
                'token' => env('SIAT_TEST_TOKEN'),
                'mock_siat' => false,
                'is_active' => true,
            ],
            'local_mock_compra_venta' => [
                'label' => 'Local simulado - Compra venta',
                'nit' => '123456789',
                'business_name' => 'EMPRESA DEMO LOCAL',
                'municipality' => 'Santa Cruz',
                'phone' => '70000000',
                'system_code' => 'SYS-DEMO-LOCAL',
                'environment_code' => 2,
                'modality_code' => 2,
                'emission_type_code' => 1,
                'invoice_type_code' => 1,
                'document_sector_code' => 1,
                'economic_activity_code' => 471100,
                'sin_product_code' => 99100,
                'siat_branch_code' => 0,
                'point_of_sale_code' => 0,
                'token' => 'token-demo-local',
                'mock_siat' => true,
                'is_active' => true,
            ],
        ];
    }

    public function preset(string $key): array
    {
        return $this->presets()[$key] ?? [];
    }
}
