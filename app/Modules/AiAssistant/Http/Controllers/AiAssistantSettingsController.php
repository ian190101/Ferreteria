<?php

namespace App\Modules\AiAssistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AiAssistant\Models\AiAssistantApiClient;
use App\Modules\AiAssistant\Models\AiAssistantApiLog;
use App\Modules\AiAssistant\Models\AiAssistantChannel;
use App\Modules\AiAssistant\Services\AiAssistantFastApiClient;
use App\Modules\AiAssistant\Services\AiAssistantKnowledgeIndexService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AiAssistantSettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('AiAssistant/Settings', [
            'channels' => AiAssistantChannel::query()
                ->with('branch:id,name')
                ->latest()
                ->get()
                ->map(fn (AiAssistantChannel $channel): array => [
                    'id' => $channel->id,
                    'provider' => $channel->provider,
                    'name' => $channel->name,
                    'status' => $channel->status,
                    'branch' => $channel->branch?->name,
                    'webhook_url' => $channel->webhook_url,
                    'settings' => $channel->settings ?? [],
                    'allowed_scopes' => $channel->allowed_scopes ?? [],
                    'has_credentials' => filled($channel->encrypted_credentials),
                    'last_verified_at' => $channel->last_verified_at?->toDateTimeString(),
                ]),
            'apiClients' => AiAssistantApiClient::query()
                ->with(['branch:id,name', 'user:id,name'])
                ->latest()
                ->get(['id', 'branch_id', 'user_id', 'name', 'scopes', 'status', 'rate_limit_per_minute', 'last_used_at', 'created_at']),
            'apiLogs' => AiAssistantApiLog::query()
                ->with('client:id,name')
                ->latest()
                ->limit(40)
                ->get(['id', 'ai_assistant_api_client_id', 'endpoint', 'scope', 'status', 'http_status', 'created_at']),
            'providers' => [
                AiAssistantChannel::PROVIDER_INTERNAL => 'Interfaz ERP',
                AiAssistantChannel::PROVIDER_TELEGRAM => 'Telegram',
                AiAssistantChannel::PROVIDER_WHATSAPP => 'WhatsApp Cloud API',
                AiAssistantChannel::PROVIDER_META => 'Meta',
            ],
            'scopes' => ['chat', 'voice', 'orders', 'reports', 'analytics', 'external_api'],
            'modelModes' => ['cpu_light', 'gpu_medium', 'external_openai_compatible', 'disabled'],
        ]);
    }

    public function storeChannel(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'in:internal,telegram,whatsapp,meta'],
            'name' => ['required', 'string', 'max:120'],
            'status' => ['required', 'in:active,inactive'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'webhook_url' => ['nullable', 'url', 'max:500'],
            'credentials_json' => ['nullable', 'json', 'max:5000'],
            'allowed_scopes' => ['nullable', 'array'],
            'allowed_scopes.*' => ['string', 'in:chat,voice,orders,reports,analytics,external_api'],
            'settings_json' => ['nullable', 'json', 'max:5000'],
        ]);

        AiAssistantChannel::query()->updateOrCreate(
            ['provider' => $validated['provider'], 'name' => $validated['name']],
            [
                'branch_id' => $validated['branch_id'] ?? null,
                'status' => $validated['status'],
                'webhook_url' => $validated['webhook_url'] ?? null,
                'encrypted_credentials' => filled($validated['credentials_json'] ?? null)
                    ? Crypt::encryptString((string) $validated['credentials_json'])
                    : null,
                'allowed_scopes' => $validated['allowed_scopes'] ?? [],
                'settings' => filled($validated['settings_json'] ?? null) ? json_decode($validated['settings_json'], true) : [],
            ]
        );

        return redirect()->route('ai-assistant.settings.index')->with('success', 'Canal IA guardado correctamente.');
    }

    public function storeApiClient(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', 'in:chat,voice,orders,reports,analytics,external_api'],
            'rate_limit_per_minute' => ['required', 'integer', 'min:1', 'max:300'],
        ]);

        $token = 'aia_'.Str::random(48);
        AiAssistantApiClient::query()->create([
            'branch_id' => $request->user()->branch_id,
            'user_id' => $request->user()->id,
            'name' => $validated['name'],
            'token_hash' => hash('sha256', $token),
            'scopes' => $validated['scopes'],
            'status' => 'active',
            'rate_limit_per_minute' => $validated['rate_limit_per_minute'],
        ]);

        return redirect()
            ->route('ai-assistant.settings.index')
            ->with('success', 'Cliente API creado. Token: '.$token.' Guardalo ahora porque no se volvera a mostrar.');
    }

    public function revokeApiClient(AiAssistantApiClient $client): RedirectResponse
    {
        $client->update(['status' => 'revoked']);

        return redirect()
            ->route('ai-assistant.settings.index')
            ->with('success', 'Cliente API revocado correctamente.');
    }

    public function health(AiAssistantFastApiClient $client): RedirectResponse
    {
        $result = $client->health();

        return redirect()
            ->route('ai-assistant.settings.index')
            ->with(($result['ok'] ?? false) ? 'success' : 'warning', (string) ($result['message'] ?? 'Health check ejecutado.'));
    }

    public function indexKnowledge(AiAssistantKnowledgeIndexService $indexer): RedirectResponse
    {
        $result = $indexer->indexCatalog();

        return redirect()
            ->route('ai-assistant.settings.index')
            ->with('success', 'Fuentes IA indexadas: '.$result['indexed'].'.');
    }
}
