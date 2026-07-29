<?php

namespace App\Modules\Billing\Services;

use DOMDocument;
use Illuminate\Validation\ValidationException;

class SiatXmlValidator
{
    public function validateCompraVenta(string $xml): void
    {
        $this->validateWellFormed($xml);

        $schemaPath = base_path('resources/siat/xsd/facturaComputarizadaCompraVenta.xsd');

        if (! is_file($schemaPath)) {
            throw ValidationException::withMessages([
                'xml' => 'No se encontro el XSD local de Factura Compra Venta. Verifica la instalacion fiscal antes de emitir.',
            ]);
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $document->resolveExternals = false;
            $document->substituteEntities = false;

            if (! $document->loadXML($xml, LIBXML_NONET)) {
                $this->throwXmlErrors('El XML fiscal no esta bien formado.');
            }

            if (! $document->schemaValidate($schemaPath)) {
                $this->throwXmlErrors('El XML fiscal no cumple el esquema XSD de Factura Compra Venta.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function validateWellFormed(string $xml): void
    {
        if (preg_match('/<!DOCTYPE/i', $xml)) {
            throw ValidationException::withMessages([
                'xml' => 'El XML fiscal no puede contener definiciones DOCTYPE por seguridad.',
            ]);
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $document->resolveExternals = false;
            $document->substituteEntities = false;

            if (! $document->loadXML($xml, LIBXML_NONET)) {
                $this->throwXmlErrors('El XML fiscal no esta bien formado.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function throwXmlErrors(string $message): never
    {
        $errors = collect(libxml_get_errors())
            ->map(fn ($error) => trim($error->message))
            ->filter()
            ->unique()
            ->take(5)
            ->implode(' ');

        throw ValidationException::withMessages([
            'xml' => trim($message.' '.$errors),
        ]);
    }
}
