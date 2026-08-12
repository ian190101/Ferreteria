<?php

namespace App\Modules\AiAssistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AiAssistant\Models\AiAssistantApiClient;
use App\Modules\AiAssistant\Models\AiAssistantChannel;
use App\Modules\AiAssistant\Services\AiAssistantConversationService;
use App\Modules\AiAssistant\Services\AiAssistantFastApiClient;
use App\Modules\AiAssistant\Services\AiAssistantPolicy;
use App\Modules\AiAssistant\Services\AiAssistantToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiAssistantExternalApiController extends Controller
{
    public function status(Request $request, AiAssistantPolicy $policy): JsonResponse
    {
        $client = $this->client($request, 'external_api');

        return response()->json([
            'ok' => $policy->externalApiEnabled(),
            'client' => $client->name,
            'scopes' => $client->scopes,
        ]);
    }

    public function chat(Request $request, AiAssistantPolicy $policy, AiAssistantConversationService $chat): JsonResponse
    {
        abort_unless($policy->externalApiEnabled(), 404);
        $client = $this->client($request, 'chat');
        $user = $client->user;
        abort_unless($user !== null, 403, 'El cliente API no tiene usuario operativo vinculado.');

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'external_user_ref' => ['nullable', 'string', 'max:180'],
        ]);

        $channel = AiAssistantChannel::query()->firstOrCreate(
            ['provider' => AiAssistantChannel::PROVIDER_INTERNAL, 'name' => 'API externa'],
            [
                'branch_id' => $client->branch_id,
                'status' => AiAssistantChannel::STATUS_ACTIVE,
                'allowed_scopes' => ['chat', 'external_api'],
                'settings' => ['source' => 'api'],
            ]
        );

        $result = $chat->sendExternal(
            $user,
            $channel,
            (string) ($validated['external_user_ref'] ?? 'api-client-'.$client->id),
            $validated['message']
        );

        return response()->json([
            'ok' => true,
            'answer' => $result['answer'],
            'intent' => $result['intent'],
            'tool_result' => $this->safeToolResult($result['tool_result'] ?? null),
        ]);
    }

    public function transcribe(Request $request, AiAssistantPolicy $policy, AiAssistantFastApiClient $fastApi): JsonResponse
    {
        abort_unless($policy->externalApiEnabled(), 404);
        $this->client($request, 'voice');

        $validated = $request->validate([
            'audio_path' => ['required', 'string', 'max:500'],
        ]);

        return response()->json($fastApi->transcribe($validated['audio_path']));
    }

    public function tools(Request $request, AiAssistantPolicy $policy, AiAssistantToolRegistry $tools): JsonResponse
    {
        abort_unless($policy->externalApiEnabled(), 404);
        $client = $this->client($request, 'external_api');
        $user = $client->user;
        abort_unless($user !== null, 403, 'El cliente API no tiene usuario operativo vinculado.');

        $validated = $request->validate([
            'tool' => ['required', 'string', 'max:80'],
            'input' => ['nullable', 'array'],
        ]);

        $tool = $validated['tool'];
        if (str_starts_with($tool, 'generar_exportacion')) {
            abort_unless(in_array('reports', $client->scopes ?? [], true), 403, 'Scope reports requerido.');
        }
        if (str_starts_with($tool, 'crear_')) {
            abort_unless(in_array('orders', $client->scopes ?? [], true), 403, 'Scope orders requerido.');
        }

        return response()->json([
            'ok' => true,
            'tool' => $tool,
            'result' => $this->safeToolResult($tools->run($user, $tool, $validated['input'] ?? [])),
        ]);
    }

    private function client(Request $request, string $scope): AiAssistantApiClient
    {
        $token = str($request->bearerToken() ?? '')->trim()->toString();
        abort_if($token === '', 401, 'Token requerido.');

        $client = AiAssistantApiClient::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('status', 'active')
            ->firstOrFail();

        abort_unless(in_array($scope, $client->scopes ?? [], true), 403, 'Scope no permitido.');
        $client->forceFill(['last_used_at' => now()])->save();

        return $client->loadMissing('user');
    }

    private function safeToolResult(?array $result): ?array
    {
        if ($result === null) {
            return null;
        }

        unset($result['raw_sql'], $result['credentials'], $result['token']);

        return $result;
    }
}
