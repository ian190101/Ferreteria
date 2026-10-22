<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class SystemErrorResponder
{
    public function respond(Response $response, Throwable $exception, Request $request): Response
    {
        $status = match (true) {
            $exception instanceof ValidationException => $exception->status,
            $exception instanceof AuthenticationException => 401,
            default => $response->getStatusCode(),
        };
        if ($status < 400) {
            return $response;
        }
        $details = app(SystemErrorDetails::class)->describe($exception, $status);
        try {
            Log::warning('Operación rechazada o fallida.', ['system_error' => $details, 'method' => $request->method(), 'path' => $request->path()]);
        } catch (Throwable) {
            // Un fallo del registro no debe ocultar el error original al usuario.
        }

        if ($response->isRedirection() && $request->hasSession()) {
            $request->session()->flash('system_error', $details);
        } elseif ($request->expectsJson() || $request->header('X-Inertia')) {
            $headers = [];
            foreach (['Retry-After', 'Allow', 'WWW-Authenticate'] as $name) {
                if ($response->headers->has($name)) {
                    $headers[$name] = $response->headers->get($name);
                }
            }
            $response = response()->json(['message' => $details['explanation'], 'errors' => $details['errors'], 'system_error' => $details], $status, $headers);
        } else {
            // La página de emergencia no inicia Inertia ni vuelve a consultar la base que pudo fallar.
            $response = response()->view('errors.system', ['error' => $details], $status);
        }
        $response->headers->set('X-System-Error-Code', $details['code']);
        $response->headers->set('X-Error-Reference', $details['id']);

        return $response;
    }
}
