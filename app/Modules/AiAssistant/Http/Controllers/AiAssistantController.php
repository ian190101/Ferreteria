<?php

namespace App\Modules\AiAssistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AiAssistant\Models\AiAssistantConversation;
use App\Modules\AiAssistant\Models\AiAssistantMessage;
use App\Modules\AiAssistant\Models\AiAssistantToolRun;
use App\Modules\AiAssistant\Services\AiAssistantActionService;
use App\Modules\AiAssistant\Services\AiAssistantConversationService;
use App\Modules\AiAssistant\Services\AiAssistantGeneratedFileService;
use App\Modules\AiAssistant\Services\AiAssistantPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AiAssistantController extends Controller
{
    public function index(Request $request, AiAssistantPolicy $policy): Response
    {
        $policy->ensureInternalChat($request->user());

        $conversations = AiAssistantConversation::query()
            ->where('user_id', $request->user()->id)
            ->with(['messages' => fn ($query) => $query->latest()->limit(8)])
            ->latest('last_message_at')
            ->limit(12)
            ->get();

        $toolRuns = AiAssistantToolRun::query()
            ->whereIn('ai_assistant_conversation_id', $conversations->pluck('id'))
            ->whereIn('status', ['pending_confirmation', 'confirmed'])
            ->latest()
            ->limit(20)
            ->get();

        return Inertia::render('AiAssistant/Index', [
            'conversations' => $conversations,
            'pendingActions' => $toolRuns,
            'voiceEnabled' => $policy->voiceEnabled(),
            'limits' => [
                'maxAudioMb' => 12,
                'maxMessageLength' => 2000,
            ],
        ]);
    }

    public function send(Request $request, AiAssistantPolicy $policy, AiAssistantConversationService $chat): RedirectResponse
    {
        $policy->ensureInternalChat($request->user());

        $validated = $request->validate([
            'conversation_id' => ['nullable', 'integer', 'exists:ai_assistant_conversations,id'],
            'message' => ['required_without:audio', 'nullable', 'string', 'max:2000'],
            'audio' => ['nullable', 'file', 'mimetypes:audio/mpeg,audio/ogg,audio/wav,audio/webm,audio/mp4', 'max:12288'],
        ]);

        $conversation = null;
        if (filled($validated['conversation_id'] ?? null)) {
            $conversation = AiAssistantConversation::query()
                ->where('user_id', $request->user()->id)
                ->findOrFail((int) $validated['conversation_id']);
        }

        $audioPath = null;
        $messageType = 'text';
        $message = trim((string) ($validated['message'] ?? ''));

        if ($request->hasFile('audio')) {
            abort_unless($policy->voiceEnabled(), 403, 'El perfil no permite mensajes de audio.');
            $audioPath = $request->file('audio')->store('ai-assistant/audio', 'local');
            $messageType = 'audio';
            $message = $message !== '' ? $message : 'Audio recibido para transcripcion y analisis.';
        }

        $chat->sendInternal($request->user(), $message, $conversation, $messageType, $audioPath);

        return redirect()->route('ai-assistant.index')->with('success', 'Mensaje procesado correctamente.');
    }

    public function download(Request $request, AiAssistantPolicy $policy, AiAssistantMessage $message, AiAssistantGeneratedFileService $files): BinaryFileResponse
    {
        $policy->ensureInternalChat($request->user());

        return $files->download($request->user(), $message);
    }

    public function confirm(Request $request, AiAssistantPolicy $policy, AiAssistantToolRun $toolRun, AiAssistantActionService $actions): RedirectResponse
    {
        $policy->ensureInternalChat($request->user());
        $actions->confirm($request->user(), $toolRun);

        return redirect()->route('ai-assistant.index')->with('success', 'Accion IA confirmada.');
    }

    public function cancel(Request $request, AiAssistantPolicy $policy, AiAssistantToolRun $toolRun, AiAssistantActionService $actions): RedirectResponse
    {
        $policy->ensureInternalChat($request->user());
        $actions->cancel($request->user(), $toolRun);

        return redirect()->route('ai-assistant.index')->with('success', 'Accion IA cancelada.');
    }
}
