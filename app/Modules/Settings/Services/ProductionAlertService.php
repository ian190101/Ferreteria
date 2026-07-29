<?php

namespace App\Modules\Settings\Services;

use App\Modules\Settings\Models\SystemSetting;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ProductionAlertService
{
    public function notify(string $subject, string $message, array $context = []): bool
    {
        $email = $this->alertEmail();

        if (! $email) {
            app(ProductionLogService::class)->record('maintenance', 'warning', 'production_alert_not_sent', 'No se envio alerta tecnica porque no hay correo configurado.', [
                'subject' => $subject,
            ]);

            return false;
        }

        try {
            Mail::raw($this->body($message, $context), function ($mail) use ($email, $subject) {
                $mail->to($email)->subject('[Sistema] '.$subject);
            });

            app(ProductionLogService::class)->record('maintenance', 'info', 'production_alert_sent', 'Alerta tecnica enviada correctamente.', [
                'subject' => $subject,
                'email' => $email,
            ]);

            return true;
        } catch (\Throwable $exception) {
            app(ProductionLogService::class)->record('maintenance', 'error', 'production_alert_failed', 'No se pudo enviar la alerta tecnica.', [
                'subject' => $subject,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function alertEmail(): ?string
    {
        $settings = SystemSetting::query()->where('key', 'production.maintenance')->value('value');
        $email = is_array($settings) ? ($settings['technical_alert_email'] ?? null) : null;
        $email ??= env('TECHNICAL_ALERT_EMAIL');

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function body(string $message, array $context): string
    {
        $safeContext = collect($context)
            ->mapWithKeys(fn ($value, $key) => [
                $key => Str::contains((string) $key, ['password', 'token', 'secret', 'license_key', 'certificate'])
                    ? '[OCULTO]'
                    : $value,
            ])
            ->all();

        return trim($message.PHP_EOL.PHP_EOL.'Contexto:'.PHP_EOL.json_encode($safeContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
