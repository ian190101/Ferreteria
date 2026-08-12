<?php

namespace App\Modules\AiAssistant\Services;

use App\Modules\SystemSuperadmin\Services\ActiveBusinessProfile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class AiAssistantFastApiClient
{
    public function health(): array
    {
        return $this->post('/health', []);
    }

    public function chat(array $payload): array
    {
        return $this->post('/chat', $payload);
    }

    public function transcribe(string $audioPath): array
    {
        return $this->post('/transcribe', ['audio_path' => $audioPath]);
    }

    public function indexEmbeddings(array $documents): array
    {
        return $this->post('/embed/index', ['documents' => $documents]);
    }

    private function post(string $path, array $payload): array
    {
        $settings = ActiveBusinessProfile::payload()['ai_assistant'] ?? [];
        $baseUrl = rtrim((string) data_get($settings, 'fastapi.url', ''), '/');

        if ($baseUrl === '') {
            return [
                'ok' => false,
                'fallback' => true,
                'message' => 'Servicio FastAPI no configurado; se uso el motor interno controlado.',
            ];
        }

        try {
            $token = (string) data_get($settings, 'fastapi.internal_token', '');
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            $timestamp = (string) now()->timestamp;
            $signature = $token !== '' ? 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $token) : '';

            $response = Http::timeout((int) data_get($settings, 'fastapi.timeout_seconds', 12))
                ->withToken($token)
                ->withHeaders(array_filter([
                    'X-ERP-Timestamp' => $timestamp,
                    'X-ERP-Signature' => $signature,
                ]))
                ->acceptJson()
                ->withBody($body, 'application/json')
                ->post($baseUrl.$path);

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'fallback' => true,
                    'message' => 'FastAPI respondio con estado '.$response->status().'.',
                ];
            }

            return ['ok' => true, ...$response->json()];
        } catch (ConnectionException $exception) {
            return [
                'ok' => false,
                'fallback' => true,
                'message' => 'No se pudo conectar con FastAPI: '.$exception->getMessage(),
            ];
        }
    }
}
