<?php

namespace App\Modules\AiAssistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AiAssistant\Models\AiAssistantChannel;
use App\Modules\AiAssistant\Services\AiAssistantConversationService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class AiAssistantWebhookController extends Controller
{
    public function telegram(Request $request, AiAssistantChannel $channel, AiAssistantConversationService $chat): JsonResponse
    {
        abort_unless($channel->provider === AiAssistantChannel::PROVIDER_TELEGRAM && $channel->status === AiAssistantChannel::STATUS_ACTIVE, 404);
        $this->ensureWebhookSecret($request, $channel);

        $payload = $request->all();
        $message = data_get($payload, 'message.text')
            ?? data_get($payload, 'edited_message.text')
            ?? data_get($payload, 'message.caption')
            ?? 'Mensaje recibido sin texto. Si enviaste audio, la transcripcion se procesara cuando el conector tenga el archivo disponible.';
        $externalRef = (string) (data_get($payload, 'message.from.id') ?? data_get($payload, 'edited_message.from.id') ?? data_get($payload, 'message.chat.id') ?? 'telegram-unknown');
        $user = $this->operatingUser($channel);

        $result = $chat->sendExternal($user, $channel, $externalRef, (string) $message, filled(data_get($payload, 'message.voice')) ? 'audio' : 'text');
        $this->sendTelegramReply($channel, (string) data_get($payload, 'message.chat.id'), $result['answer']);

        return response()->json([
            'ok' => true,
            'status' => 'processed',
            'provider' => 'telegram',
            'answer' => $result['answer'],
        ]);
    }

    public function meta(Request $request, AiAssistantChannel $channel, AiAssistantConversationService $chat): JsonResponse
    {
        abort_unless(in_array($channel->provider, [AiAssistantChannel::PROVIDER_META, AiAssistantChannel::PROVIDER_WHATSAPP], true), 404);
        abort_unless($channel->status === AiAssistantChannel::STATUS_ACTIVE, 404);
        $this->ensureMetaSignature($request, $channel);

        $entry = collect((array) $request->input('entry'))->first();
        $change = collect((array) data_get($entry, 'changes'))->first();
        $messagePayload = collect((array) data_get($change, 'value.messages'))->first();

        if (! is_array($messagePayload)) {
            return response()->json(['ok' => true, 'status' => 'ignored']);
        }

        $message = (string) (data_get($messagePayload, 'text.body') ?? data_get($messagePayload, 'interactive.button_reply.title') ?? 'Mensaje recibido sin texto.');
        $externalRef = (string) data_get($messagePayload, 'from', 'meta-unknown');
        $user = $this->operatingUser($channel);
        $result = $chat->sendExternal($user, $channel, $externalRef, $message);

        return response()->json([
            'ok' => true,
            'status' => 'processed',
            'provider' => $channel->provider,
            'answer' => $result['answer'],
        ]);
    }

    public function metaVerify(Request $request): JsonResponse|string
    {
        $challenge = $request->query('hub_challenge') ?? $request->query('hub.challenge');

        return filled($challenge)
            ? (string) $challenge
            : response()->json(['ok' => false, 'message' => 'Challenge faltante.'], 422);
    }

    private function operatingUser(AiAssistantChannel $channel): User
    {
        $userId = (int) data_get($channel->settings ?? [], 'user_id', 0);
        $query = User::query();

        if ($userId > 0) {
            $query->whereKey($userId);
        } elseif ($channel->branch_id !== null) {
            $query->where('branch_id', $channel->branch_id);
        }

        $user = $query->first();
        abort_unless($user !== null, 422, 'El canal IA no tiene usuario operativo configurado.');

        return $user;
    }

    private function ensureWebhookSecret(Request $request, AiAssistantChannel $channel): void
    {
        $expected = (string) data_get($channel->settings ?? [], 'secret_token', '');
        if ($expected === '') {
            return;
        }

        abort_unless(hash_equals($expected, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403, 'Webhook no autorizado.');
    }

    private function ensureMetaSignature(Request $request, AiAssistantChannel $channel): void
    {
        $appSecret = (string) data_get($this->credentials($channel), 'app_secret', '');
        if ($appSecret === '') {
            return;
        }

        $signature = (string) $request->header('X-Hub-Signature-256', '');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $appSecret);
        abort_unless(hash_equals($expected, $signature), 403, 'Firma Meta no valida.');
    }

    private function sendTelegramReply(AiAssistantChannel $channel, string $chatId, string $answer): void
    {
        if ($chatId === '') {
            return;
        }

        $token = (string) data_get($this->credentials($channel), 'bot_token', '');
        if ($token === '') {
            return;
        }

        try {
            Http::timeout(5)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => mb_substr($answer, 0, 3900),
            ]);
        } catch (ConnectionException) {
            // El webhook ya quedo registrado; no se debe romper por una falla temporal de Telegram.
        }
    }

    private function credentials(AiAssistantChannel $channel): array
    {
        if (blank($channel->encrypted_credentials)) {
            return [];
        }

        $decoded = json_decode(Crypt::decryptString($channel->encrypted_credentials), true);

        return is_array($decoded) ? $decoded : [];
    }
}
