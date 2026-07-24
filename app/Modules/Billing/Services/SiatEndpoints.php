<?php

namespace App\Modules\Billing\Services;

class SiatEndpoints
{
    public function wsdl(int $environmentCode, string $service): string
    {
        $base = $environmentCode === 1
            ? config('services.siat.production_base', 'https://siatservicios.impuestos.gob.bo/v2')
            : config('services.siat.pilot_base', 'https://pilotosiatservicios.impuestos.gob.bo/v2');

        return rtrim($base, '/').'/'.$service.'?wsdl';
    }
}
