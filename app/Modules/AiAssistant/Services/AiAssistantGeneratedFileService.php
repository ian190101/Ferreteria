<?php

namespace App\Modules\AiAssistant\Services;

use App\Models\User;
use App\Modules\AiAssistant\Models\AiAssistantMessage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AiAssistantGeneratedFileService
{
    public function download(User $user, AiAssistantMessage $message): BinaryFileResponse
    {
        $conversation = $message->conversation;
        abort_unless($conversation !== null, 404);

        if (! $user->isSuperAdministrator()) {
            abort_unless((int) $conversation->user_id === (int) $user->id, 403);
        }

        $path = (string) data_get($message->metadata ?? [], 'tool_result.path', '');
        abort_unless(str_starts_with($path, 'ai-assistant/'), 404);
        abort_unless(Storage::disk('local')->exists($path), 404);

        $absolutePath = Storage::disk('local')->path($path);
        $name = basename($path);

        return response()->download($absolutePath, $name);
    }
}
