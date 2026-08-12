<?php

namespace App\Modules\AiAssistant\Services;

use App\Models\User;
use App\Modules\AiAssistant\Models\AiAssistantMessage;
use App\Modules\AiAssistant\Models\AiAssistantToolRun;

class AiAssistantActionService
{
    public function confirm(User $user, AiAssistantToolRun $toolRun): AiAssistantMessage
    {
        $this->authorize($user, $toolRun);

        abort_unless($toolRun->status === 'pending_confirmation', 422, 'La accion no esta pendiente de confirmacion.');
        abort_unless($user->can('ai-assistant.actions.approve'), 403, 'No tienes permiso para confirmar acciones IA.');

        $toolRun->update([
            'status' => 'confirmed',
            'finished_at' => now(),
            'output' => array_merge($toolRun->output ?? [], [
                'confirmed_by' => $user->id,
                'confirmed_at' => now()->toDateTimeString(),
            ]),
        ]);

        return AiAssistantMessage::query()->create([
            'ai_assistant_conversation_id' => $toolRun->ai_assistant_conversation_id,
            'user_id' => $user->id,
            'role' => AiAssistantMessage::ROLE_SYSTEM,
            'message_type' => 'text',
            'content' => 'Accion IA confirmada. El flujo quedo autorizado para continuar por el modulo operativo correspondiente.',
            'metadata' => [
                'tool_run_id' => $toolRun->id,
                'status' => 'confirmed',
            ],
        ]);
    }

    public function cancel(User $user, AiAssistantToolRun $toolRun): AiAssistantMessage
    {
        $this->authorize($user, $toolRun);
        abort_unless(in_array($toolRun->status, ['pending_confirmation', 'confirmed'], true), 422, 'La accion no puede cancelarse.');

        $toolRun->update([
            'status' => 'cancelled',
            'finished_at' => now(),
            'output' => array_merge($toolRun->output ?? [], [
                'cancelled_by' => $user->id,
                'cancelled_at' => now()->toDateTimeString(),
            ]),
        ]);

        return AiAssistantMessage::query()->create([
            'ai_assistant_conversation_id' => $toolRun->ai_assistant_conversation_id,
            'user_id' => $user->id,
            'role' => AiAssistantMessage::ROLE_SYSTEM,
            'message_type' => 'text',
            'content' => 'Accion IA cancelada. No se ejecuto ningun cambio operativo.',
            'metadata' => [
                'tool_run_id' => $toolRun->id,
                'status' => 'cancelled',
            ],
        ]);
    }

    private function authorize(User $user, AiAssistantToolRun $toolRun): void
    {
        $conversation = $toolRun->conversation;
        abort_unless($conversation !== null, 404);

        if (! $user->isSuperAdministrator()) {
            abort_unless((int) $conversation->user_id === (int) $user->id, 403);
        }
    }
}
