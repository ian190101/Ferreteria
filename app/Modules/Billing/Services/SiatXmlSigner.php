<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\SiatBranchSetting;

class SiatXmlSigner
{
    public function signIfRequired(string $xml, SiatBranchSetting $setting): string
    {
        if ($setting->modality_code !== SiatBranchSetting::MODALITY_ELECTRONIC) {
            return $xml;
        }

        // La firma real se conecta con XML-DSig y certificado .p12 cuando la sucursal use Electronica en Linea.
        // Se mantiene aislado para que Computarizada en Linea pase pruebas sin requerir certificado digital.
        if (! class_exists(\RobRichards\XMLSecLibs\XMLSecurityDSig::class)) {
            throw new \RuntimeException('Para Facturacion Electronica en Linea falta instalar xmlseclibs y configurar certificado digital.');
        }

        throw new \RuntimeException('La firma XML-DSig esta preparada como punto de extension; primero certificar Computarizada en Linea.');
    }
}
