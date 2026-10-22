<?php

declare(strict_types=1);

namespace App\Modules\SystemSuperadmin\Services;

use App\Modules\Settings\Services\ProductionUpdateService;
use App\Support\OperationalWriteGate;
use App\Support\SystemErrorDetails;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class RepositoryDeploymentService
{
    public function isConfigured(): bool
    {
        $command = config('deployment.restart_command');

        return is_array($command) && $command !== [] && collect($command)->every(fn ($value) => is_string($value) && $value !== '');
    }

    public function assertReady(string $root): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Configura DEPLOY_RESTART_COMMAND_JSON con los argumentos del reinicio supervisado antes de actualizar código.');
        }
        foreach (['composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'artisan'] as $file) {
            if (! is_file($root.'/'.$file)) {
                throw new RuntimeException('Falta un archivo requerido para el despliegue: '.$file);
            }
        }
        foreach (['composer', 'npm'] as $name) {
            $this->executable($name);
        }
    }

    public function prepare(string $root): void
    {
        // El respaldo comparte la barrera exclusiva; un subproceso no debe intentar readquirirla.
        app(ProductionUpdateService::class)->prepare();
        $this->stage($root, 'modo mantenimiento', [PHP_BINARY, 'artisan', 'down', '--retry=60', '--no-interaction']);
    }

    public function coordinate(\Closure $action): array
    {
        return app(OperationalWriteGate::class)->exclusive($action);
    }

    public function complete(string $root): array
    {
        $stages = [
            'dependencias PHP' => [$this->executable('composer'), 'install', '--no-dev', '--no-interaction', '--prefer-dist', '--optimize-autoloader'],
            'dependencias frontend' => [$this->executable('npm'), 'ci', '--include=dev'],
            'compilación frontend' => [$this->executable('npm'), 'run', 'build'],
            'migraciones' => [PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction'],
            'limpieza de caché' => [PHP_BINARY, 'artisan', 'optimize:clear', '--no-interaction'],
            'caché de configuración' => [PHP_BINARY, 'artisan', 'config:cache', '--no-interaction'],
            'caché de rutas' => [PHP_BINARY, 'artisan', 'route:cache', '--no-interaction'],
            'caché de vistas' => [PHP_BINARY, 'artisan', 'view:cache', '--no-interaction'],
            'reinicio de trabajadores' => [PHP_BINARY, 'artisan', 'queue:restart', '--no-interaction'],
            'reinicio supervisado' => config('deployment.restart_command'),
            'apertura del servicio' => [PHP_BINARY, 'artisan', 'up', '--no-interaction'],
        ];
        foreach ($stages as $name => $command) {
            $this->stage($root, $name, $command);
        }

        return array_keys($stages);
    }

    protected function stage(string $root, string $name, array $command): void
    {
        $process = new Process($command, $root);
        $process->setTimeout(900);
        $process->run();
        if (! $process->isSuccessful()) {
            $detail = app(SystemErrorDetails::class)->sanitize($process->getErrorOutput() ?: $process->getOutput());
            throw new RuntimeException('Falló la etapa «'.$name.'» (código '.($process->getExitCode() ?? 'sin salida').'): '.$detail);
        }
    }

    protected function executable(string $name): string
    {
        $path = (new ExecutableFinder)->find($name);
        if ($path === null) {
            throw new RuntimeException('No está disponible el ejecutable requerido: '.$name);
        }

        return $path;
    }
}
