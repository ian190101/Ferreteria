<?php

namespace App\Modules\Billing\Services;

use RuntimeException;

class SiatGzipService
{
    public function compress(string $contents): string
    {
        $compressed = gzencode($contents, 9);

        if ($compressed === false) {
            throw new RuntimeException('No se pudo comprimir el XML fiscal en formato Gzip.');
        }

        return $compressed;
    }

    public function compressBase64(string $contents): string
    {
        return base64_encode($this->compress($contents));
    }
}
