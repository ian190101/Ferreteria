<?php

namespace App\Modules\AiAssistant\Services;

use App\Models\User;
use App\Modules\SystemSuperadmin\Services\ActiveBusinessProfile;

class AiAssistantPolicy
{
    public function enabled(): bool
    {
        return ActiveBusinessProfile::enabled('ai_assistant')
            && ActiveBusinessProfile::capable('uses_ai_assistant')
            && ActiveBusinessProfile::featureEnabled('ai_assistant_engine');
    }

    public function internalChatEnabled(): bool
    {
        return $this->enabled()
            && ActiveBusinessProfile::capable('uses_ai_internal_chat');
    }

    public function voiceEnabled(): bool
    {
        return $this->enabled()
            && ActiveBusinessProfile::capable('uses_ai_voice');
    }

    public function externalApiEnabled(): bool
    {
        return $this->enabled()
            && ActiveBusinessProfile::capable('uses_ai_external_api');
    }

    public function ensureInternalChat(User $user): void
    {
        abort_unless($this->internalChatEnabled(), 404);
        abort_unless($user->can('ai-assistant.view') && $user->can('ai-assistant.chat'), 403);
    }
}
