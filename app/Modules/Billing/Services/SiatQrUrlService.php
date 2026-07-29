<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\SiatBranchSetting;
use App\Modules\Billing\Models\SiatInvoice;

class SiatQrUrlService
{
    public function url(SiatInvoice $invoice, int $size = 2): string
    {
        $base = (int) $invoice->environment_code === SiatBranchSetting::ENVIRONMENT_PRODUCTION
            ? config('services.siat.production_qr_base', 'https://siat.impuestos.gob.bo/consulta/QR')
            : config('services.siat.pilot_qr_base', 'https://pilotosiat.impuestos.gob.bo/consulta/QR');

        return $base.'?'.http_build_query([
            'nit' => $invoice->branch?->siatSetting?->nit,
            'cuf' => $invoice->cuf,
            'numero' => $invoice->invoice_number,
            't' => $size,
        ]);
    }
}
