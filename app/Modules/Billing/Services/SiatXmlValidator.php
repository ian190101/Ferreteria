<?php

namespace App\Modules\Billing\Services;

use DOMDocument;
use Illuminate\Validation\ValidationException;

class SiatXmlValidator
{
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
                $errors = collect(libxml_get_errors())
                    ->map(fn ($error) => trim($error->message))
                    ->filter()
                    ->unique()
                    ->implode(' ');

                throw ValidationException::withMessages([
                    'xml' => 'El XML fiscal no esta bien formado. '.$errors,
                ]);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
