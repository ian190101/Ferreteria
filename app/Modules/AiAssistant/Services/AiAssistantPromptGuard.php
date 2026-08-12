<?php

namespace App\Modules\AiAssistant\Services;

class AiAssistantPromptGuard
{
    private const BLOCKED_PATTERNS = [
        'ignora instrucciones',
        'ignorar instrucciones',
        'olvida las reglas',
        'revela el token',
        'muestra credenciales',
        'muestra contrasena',
        'muestra password',
        'sql libre',
        'ejecuta sql',
        'drop table',
        'truncate table',
        'delete from',
        'insert into',
        'update users',
        'sin permisos',
        'saltate permisos',
        'bypass',
    ];

    public function inspect(string $message): ?array
    {
        $normalized = str($message)->lower()->ascii()->toString();

        foreach (self::BLOCKED_PATTERNS as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return [
                    'blocked' => true,
                    'reason' => 'prompt_injection',
                    'pattern' => $pattern,
                    'message' => 'No puedo ejecutar instrucciones que intenten saltar permisos, revelar secretos o usar SQL libre. Puedo ayudarte con consultas permitidas del ERP.',
                ];
            }
        }

        return null;
    }
}
