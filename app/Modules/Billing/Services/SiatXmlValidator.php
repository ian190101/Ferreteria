<?php

namespace App\Modules\Billing\Services;

use DOMDocument;
use Illuminate\Validation\ValidationException;

class SiatXmlValidator
{
    public function validateWellFormed(string $xml): void
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            if (! $document->loadXML($xml)) {
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
