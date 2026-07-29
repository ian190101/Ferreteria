<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\SiatBranchSetting;
use App\Modules\Billing\Models\SiatLog;
use SoapClient;
use SoapFault;
use Throwable;
use Illuminate\Validation\ValidationException;

class SiatSoapClient
{
    public function __construct(private readonly SiatEndpoints $endpoints) {}

    public function call(SiatBranchSetting $setting, string $service, string $method, array $payload): array
    {
        $startedAt = microtime(true);

        try {
            if (app()->environment('testing') || (bool) ($setting->options['mock_siat'] ?? false)) {
                return $this->mockResponse($setting, $service, $method, $payload, $startedAt);
            }

            $client = new SoapClient($this->endpoints->wsdl($setting->environment_code, $service), [
                'trace' => true,
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_MEMORY,
                'stream_context' => stream_context_create([
                    'http' => [
                        'header' => filled($setting->token) ? "apikey: TokenApi {$setting->token}\r\n" : '',
                        'timeout' => 20,
                    ],
                ]),
            ]);

            $response = $this->normalizeResponse($client->__soapCall($method, [$payload]));

            $this->log($setting, $service, $method, 'success', $payload, $response, null, $startedAt);

            return $response;
        } catch (SoapFault|Throwable $exception) {
            $this->log($setting, $service, $method, 'error', $payload, null, $exception->getMessage(), $startedAt);

            throw ValidationException::withMessages([
                'billing' => "No se pudo comunicar con SIAT en {$method}. Verifica token, ambiente, conexion y codigos fiscales. Detalle tecnico: {$exception->getMessage()}",
            ]);
        }
    }

    private function mockResponse(SiatBranchSetting $setting, string $service, string $method, array $payload, float $startedAt): array
    {
        $response = match ($method) {
            'cuis' => ['RespuestaCuis' => ['transaccion' => true, 'codigo' => 'CUIS-DEMO-'.$setting->branch_id]],
            'cufd' => ['RespuestaCufd' => ['transaccion' => true, 'codigo' => 'CUFD-DEMO-'.$setting->branch_id.'-'.now()->format('Ymd'), 'codigoControl' => 'CTRL-DEMO', 'direccion' => $setting->branch?->address]],
            'recepcionFactura' => ['RespuestaServicioFacturacion' => ['transaccion' => true, 'codigoEstado' => 908, 'codigoDescripcion' => 'VALIDADA', 'codigoRecepcion' => 'REC-DEMO-'.now()->timestamp]],
            'anulacionFactura' => ['RespuestaServicioFacturacion' => ['transaccion' => true, 'codigoEstado' => 905, 'codigoDescripcion' => 'ANULADA']],
            'registroEventoSignificativo' => ['RespuestaListaEventos' => ['transaccion' => true, 'codigoRecepcionEventoSignificativo' => 'EVT-DEMO-'.now()->timestamp]],
            'recepcionPaqueteFactura' => ['RespuestaServicioFacturacion' => ['transaccion' => true, 'codigoEstado' => 901, 'codigoRecepcion' => 'PKG-DEMO-'.now()->timestamp]],
            'validacionRecepcionPaqueteFactura' => ['RespuestaServicioFacturacion' => ['transaccion' => true, 'codigoEstado' => 908, 'codigoDescripcion' => 'VALIDADO']],
            default => ['RespuestaServicio' => ['transaccion' => true]],
        };

        $this->log($setting, $service, $method, 'mock', $payload, $response, null, $startedAt);

        return $response;
    }

    private function log(SiatBranchSetting $setting, string $service, string $method, string $status, array $request, ?array $response, ?string $message, float $startedAt): void
    {
        SiatLog::query()->create([
            'branch_id' => $setting->branch_id,
            'service' => $service,
            'operation' => $method,
            'status' => $status,
            'request_payload' => $this->maskPayload($request),
            'response_payload' => $response ? $this->maskPayload($response) : null,
            'message' => $message,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }

    private function maskPayload(array $payload): array
    {
        $sensitiveKeys = [
            'archivo',
            'cuf',
            'cufd',
            'cuis',
            'hashArchivo',
            'nit',
            'numeroDocumento',
            'codigoCliente',
            'codigoRecepcion',
        ];

        return collect($payload)
            ->map(function ($value, string|int $key) use ($sensitiveKeys) {
                if (in_array((string) $key, $sensitiveKeys, true)) {
                    return $this->maskedValue($value);
                }

                if (is_array($value)) {
                    return $this->maskPayload($value);
                }

                return $value;
            })
            ->all();
    }

    private function maskedValue(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '[dato protegido]';

        if (strlen($text) <= 8) {
            return '***';
        }

        return substr($text, 0, 4).'***'.substr($text, -4);
    }

    private function normalizeResponse(mixed $response): array
    {
        return json_decode(json_encode($response), true) ?: [];
    }
}
