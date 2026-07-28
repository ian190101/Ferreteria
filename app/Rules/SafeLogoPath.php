<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SafeLogoPath implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $path = trim((string) $value);

        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $path, $matches)) {
            $path = trim($matches[1]);
        }

        if ($this->isAllowed($path)) {
            return;
        }

        $fail('La ruta del logo debe ser una URL HTTPS de imagen, una ruta local del sistema o una imagen base64 segura.');
    }

    private function isAllowed(string $path): bool
    {
        if (preg_match('#^https://[^\s<>"\']+$#i', $path)) {
            return true;
        }

        if (preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?/[^\s<>"\']+$#i', $path)) {
            return true;
        }

        if (preg_match('#^data:image/(png|jpeg|jpg|webp|gif);base64,[A-Za-z0-9+/=]+$#i', $path)) {
            return true;
        }

        return preg_match('#^/?(storage|images|img|logos)/[A-Za-z0-9._/\-]+$#', $path) === 1;
    }
}
