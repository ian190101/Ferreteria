<?php

namespace App\Modules\AiAssistant\Services;

use App\Modules\AiAssistant\Models\AiAssistantApiClient;
use App\Modules\AiAssistant\Models\AiAssistantApiLog;
use Illuminate\Http\Request;

class AiAssistantApiLogger
{
    public function record(Request $request, ?AiAssistantApiClient $client, string $scope, string $status, int $httpStatus, array $metadata = []): void
    {
        AiAssistantApiLog::query()->create([
            'ai_assistant_api_client_id' => $client?->id,
            'user_id' => $client?->user_id,
            'endpoint' => $request->path(),
            'scope' => $scope,
            'status' => $status,
            'http_status' => $httpStatus,
            'ip_hash' => hash('sha256', (string) $request->ip()),
            'user_agent_hash' => hash('sha256', (string) $request->userAgent()),
            'metadata' => $metadata,
        ]);
    }
}
