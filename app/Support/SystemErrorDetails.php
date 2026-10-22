<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class SystemErrorDetails
{
    /** @return array<string, mixed> */
    public function describe(Throwable $exception, int $status): array
    {
        $origin = $exception instanceof HttpExceptionInterface && $exception->getPrevious()
            ? $exception->getPrevious() : $exception;
        $errors = $exception instanceof ValidationException ? $exception->errors() : [];
        $messages = array_merge(...array_values($errors ?: [[]]));
        $message = $messages ? implode(' ', array_unique($messages)) : $origin->getMessage();
        $code = $exception instanceof ValidationException ? 'VALIDATION_FAILED' : 'HTTP_'.$status;
        $nativeCode = (string) $origin->getCode();
        $sqlState = null;

        if ($origin instanceof QueryException) {
            $sqlState = (string) ($origin->errorInfo[0] ?? $origin->getCode());
            $nativeCode = (string) ($origin->errorInfo[1] ?? '');
            $code = 'DB_'.$sqlState.($nativeCode !== '' ? '_'.$nativeCode : '');
            // QueryException agrega SQL y bindings: el mensaje del controlador conserva el diagnóstico sin esos datos.
            $message = $origin->getPrevious()?->getMessage() ?? 'La base de datos rechazó la operación.';
            $message = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/s", "'[valor oculto]'", $message) ?? $message;
        }

        return [
            'id' => (string) Str::uuid(),
            'code' => $code,
            'http_status' => $status,
            'native_code' => $nativeCode,
            'sql_state' => $sqlState,
            'exception_type' => $origin::class,
            'technical_message' => $this->sanitize($message ?: 'El servidor no proporcionó un detalle adicional.'),
            'explanation' => $this->explanation($status, $message, $origin instanceof QueryException),
            'errors' => array_map(fn (array $items): array => array_map($this->sanitize(...), $items), $errors),
        ];
    }

    public function sanitize(string $message): string
    {
        $message = preg_replace('~\b(mysql|postgres(?:ql)?|redis|https?)://([^\s/@]+):([^\s/@]+)@~i', '$1://[credenciales ocultas]@', $message) ?? $message;
        $message = preg_replace('~\b(Bearer\s+)[\w.+/=-]+~i', '$1[oculto]', $message) ?? $message;
        $message = preg_replace('~(["\x27]?(?:password|passwd|pwd|token|secret|api[_-]?key|authorization|cookie)["\x27]?\s*[:=]\s*)("[^"]*"|\x27[^\x27]*\x27|[^\s,;&]+)~i', '$1[oculto]', $message) ?? $message;
        $message = preg_replace('~-----BEGIN [^-]*PRIVATE KEY-----.*?-----END [^-]*PRIVATE KEY-----~s', '[clave privada oculta]', $message) ?? $message;

        return mb_substr($message, 0, 4000);
    }

    private function explanation(int $status, string $message, bool $database): string
    {
        if ($database) {
            return 'No se pudo completar la operación en la base de datos. Usa el código y la referencia para revisar la causa.';
        }
        if ($status === 422) {
            if (str_contains($message, 'pagos y el saldo')) {
                return 'Los pagos registrados no coinciden con el importe marcado como pagado. Revisa ese historial antes de anular.';
            }
            if (preg_match('/stock libre|movimientos de consumo|reservas activas|fue consumida/ui', $message)) {
                return 'La mercadería necesaria para revertir la compra ya se usó, está reservada o no alcanza. Revisa sus movimientos y reservas.';
            }
            if (preg_match('/[Ff]alta.*bobina/u', $message)) {
                return 'Falta el vínculo de inventario necesario para revertir la compra. Debe revisarse antes de anular.';
            }

            return 'Revisa los datos o las condiciones indicadas en el detalle antes de volver a intentar.';
        }

        return match ($status) {
            401 => 'Necesitas iniciar sesión para continuar.',
            403 => 'Tu cuenta no tiene permiso para realizar esta operación o acceder a este recurso.',
            404 => 'El recurso solicitado no existe o dejó de estar disponible.',
            405 => 'La acción solicitada no está disponible para este recurso.',
            409 => 'El registro cambió o entra en conflicto con otra operación. Actualiza la pantalla y revisa su estado.',
            419 => 'La sesión venció. Recarga la pantalla e inicia sesión nuevamente antes de repetir la operación.',
            423 => 'La operación está bloqueada temporalmente por otra tarea o por mantenimiento.',
            429 => 'Se realizaron demasiados intentos. Espera un momento antes de volver a intentar.',
            503 => 'El servicio está temporalmente fuera de disponibilidad. Intenta nuevamente más tarde.',
            default => $status >= 500
                ? 'El servidor no pudo completar la operación. Conserva el código y la referencia para revisar el fallo.'
                : 'La solicitud no pudo completarse. Revisa el detalle indicado.',
        };
    }
}
