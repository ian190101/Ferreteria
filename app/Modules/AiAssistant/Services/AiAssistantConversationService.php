<?php

namespace App\Modules\AiAssistant\Services;

use App\Models\User;
use App\Modules\AiAssistant\Models\AiAssistantChannel;
use App\Modules\AiAssistant\Models\AiAssistantConversation;
use App\Modules\AiAssistant\Models\AiAssistantMessage;
use App\Modules\AiAssistant\Models\AiAssistantToolRun;
use Throwable;

class AiAssistantConversationService
{
    public function __construct(
        private readonly AiAssistantFastApiClient $fastApi,
        private readonly AiAssistantToolRegistry $tools,
    ) {}

    public function sendInternal(User $user, string $message, ?AiAssistantConversation $conversation = null, string $messageType = 'text', ?string $audioPath = null): array
    {
        return $this->send($user, $message, $conversation, $messageType, $audioPath);
    }

    public function sendExternal(User $user, AiAssistantChannel $channel, string $externalUserRef, string $message, string $messageType = 'text', ?string $audioPath = null): array
    {
        $conversation = AiAssistantConversation::query()
            ->where('ai_assistant_channel_id', $channel->id)
            ->where('external_user_ref', $externalUserRef)
            ->where('status', 'open')
            ->latest('last_message_at')
            ->first();

        $conversation ??= AiAssistantConversation::query()->create([
            'branch_id' => $channel->branch_id ?? $user->branch_id,
            'user_id' => $user->id,
            'ai_assistant_channel_id' => $channel->id,
            'subject_type' => $channel->provider,
            'external_user_ref' => $externalUserRef,
            'status' => 'open',
            'context' => ['provider' => $channel->provider],
            'last_message_at' => now(),
        ]);

        return $this->send($user, $message, $conversation, $messageType, $audioPath);
    }

    private function send(User $user, string $message, ?AiAssistantConversation $conversation = null, string $messageType = 'text', ?string $audioPath = null): array
    {
        $conversation ??= AiAssistantConversation::query()->create([
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
            'subject_type' => 'internal',
            'status' => 'open',
            'context' => [],
            'last_message_at' => now(),
        ]);

        $userMessage = AiAssistantMessage::query()->create([
            'ai_assistant_conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => AiAssistantMessage::ROLE_USER,
            'message_type' => $messageType,
            'content' => $message,
            'audio_path' => $audioPath,
        ]);

        if ($messageType === 'audio' && $audioPath !== null) {
            $transcription = $this->fastApi->transcribe($audioPath);
            if (($transcription['ok'] ?? false) && filled($transcription['text'] ?? null)) {
                $message = (string) $transcription['text'];
                $userMessage->update([
                    'content' => $message,
                    'metadata' => ['transcription' => $transcription],
                ]);
            }
        }

        $intent = $this->tools->detectIntent($message);
        $toolResult = null;
        $toolStatus = 'skipped';

        try {
            if (in_array($intent, $this->tools->toolsFor($user), true)) {
                $toolRun = AiAssistantToolRun::query()->create([
                    'ai_assistant_conversation_id' => $conversation->id,
                    'ai_assistant_message_id' => $userMessage->id,
                    'user_id' => $user->id,
                    'tool' => $intent,
                    'status' => 'running',
                    'input' => ['message' => $message],
                    'started_at' => now(),
                ]);

                $toolResult = $this->tools->run($user, $intent, ['message' => $message]);
                $toolStatus = 'completed';

                $toolRun->update([
                    'status' => 'completed',
                    'output' => $toolResult,
                    'finished_at' => now(),
                ]);
            }
        } catch (Throwable $exception) {
            $toolStatus = 'failed';
            $toolResult = ['error' => $exception->getMessage()];
        }

        $aiResponse = $this->fastApi->chat([
            'message' => $message,
            'intent' => $intent,
            'tool_result' => $toolResult,
            'allowed_tools' => $this->tools->toolsFor($user),
        ]);

        $answer = $this->answer($intent, $toolResult, $aiResponse, $toolStatus);

        $assistantMessage = AiAssistantMessage::query()->create([
            'ai_assistant_conversation_id' => $conversation->id,
            'role' => AiAssistantMessage::ROLE_ASSISTANT,
            'message_type' => 'text',
            'content' => $answer,
            'metadata' => [
                'intent' => $intent,
                'tool_status' => $toolStatus,
                'tool_result' => $toolResult,
                'fastapi' => $aiResponse,
            ],
        ]);

        $conversation->update([
            'last_intent' => $intent,
            'last_message_at' => now(),
        ]);

        return [
            'conversation' => $conversation->fresh(['messages' => fn ($query) => $query->latest()->limit(20)]),
            'message' => $assistantMessage,
            'answer' => $answer,
            'intent' => $intent,
            'tool_result' => $toolResult,
            'fastapi' => $aiResponse,
        ];
    }

    private function answer(string $intent, ?array $toolResult, array $aiResponse, string $toolStatus): string
    {
        if (($aiResponse['ok'] ?? false) && filled($aiResponse['answer'] ?? null)) {
            return (string) $aiResponse['answer'];
        }

        if ($toolStatus !== 'completed' || $toolResult === null) {
            return 'No pude ejecutar una herramienta segura para esa solicitud. Revisa permisos, sucursal o intenta con una pregunta mas concreta.';
        }

        return match ($intent) {
            'consultar_ventas' => "Ventas del {$toolResult['from']} al {$toolResult['to']}: {$toolResult['sales_count']} documentos por Bs {$toolResult['sales_total']}.",
            'consultar_ganancias' => "Resultado del {$toolResult['from']} al {$toolResult['to']}: ventas Bs {$toolResult['sales_total']}, ganancia estimada Bs {$toolResult['profit']} y margen {$toolResult['margin_percent']}%.",
            'consultar_stock' => "Stock permitido: {$toolResult['products_count']} productos, disponible total {$toolResult['available_total']} y {$toolResult['low_stock_count']} productos bajos.",
            'buscar_productos' => count($toolResult['items'] ?? []) > 0
                ? 'Productos encontrados: '.collect($toolResult['items'])->map(fn ($item) => "{$item['name']} Bs {$item['sale_price']}")->implode('; ')
                : 'No encontre productos activos con ese criterio.',
            'consultar_clientes' => "Clientes encontrados: {$toolResult['customers_count']}. ".collect($toolResult['items'] ?? [])->map(fn ($item) => $item['name'])->implode('; '),
            'consultar_caja' => "Caja del {$toolResult['from']} al {$toolResult['to']}: {$toolResult['open_sessions']} abiertas, {$toolResult['closed_sessions']} cerradas, efectivo esperado Bs {$toolResult['expected_cash']}.",
            'consultar_bancos' => "Bancos: {$toolResult['accounts_count']} cuentas con saldo total Bs {$toolResult['total_balance']}.",
            'consultar_estado_pedido' => count($toolResult['items'] ?? []) > 0
                ? 'Pedidos encontrados: '.collect($toolResult['items'])->map(fn ($item) => "{$item['code']} {$item['status']} Bs {$item['total']}")->implode('; ')
                : 'No encontre pedidos con ese criterio.',
            'generar_exportacion_excel', 'generar_exportacion_pdf' => "Archivo {$toolResult['format']} generado correctamente: {$toolResult['path']}.",
            default => 'Prepare la solicitud como borrador. Necesita confirmacion de un usuario autorizado antes de ejecutarse.',
        };
    }
}
