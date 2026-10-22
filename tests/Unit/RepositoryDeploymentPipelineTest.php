<?php

declare(strict_types=1);

use App\Modules\SystemSuperadmin\Services\RepositoryDeploymentService;
use Tests\TestCase;

final class RecordedDeploymentPipeline extends RepositoryDeploymentService
{
    public array $stages = [];

    public ?string $failure = null;

    protected function executable(string $name): string
    {
        return $name;
    }

    protected function stage(string $root, string $name, array $command): void
    {
        $this->stages[$name] = $command;
        if ($name === $this->failure) {
            throw new RuntimeException('Falló '.$name);
        }
    }
}

final class RepositoryDeploymentPipelineTest extends TestCase
{
    public function test_el_pipeline_incluye_todas_las_etapas_y_usa_argumentos_estructurados(): void
    {
        config(['deployment.restart_command' => ['supervisorctl', 'restart', 'php-fpm']]);
        $pipeline = new RecordedDeploymentPipeline;
        $stages = $pipeline->complete(base_path());
        $this->assertCount(11, $stages);
        $this->assertSame(['npm', 'ci', '--include=dev'], $pipeline->stages['dependencias frontend']);
        $this->assertSame(['npm', 'run', 'build'], $pipeline->stages['compilación frontend']);
        $this->assertContains('--force', $pipeline->stages['migraciones']);
        $this->assertSame(['supervisorctl', 'restart', 'php-fpm'], $pipeline->stages['reinicio supervisado']);
        $this->assertSame('apertura del servicio', array_key_last($pipeline->stages));
    }

    public function test_un_fallo_detiene_las_etapas_posteriores_y_no_reabre_el_servicio(): void
    {
        config(['deployment.restart_command' => ['supervisorctl', 'restart', 'php-fpm']]);
        $pipeline = new RecordedDeploymentPipeline;
        $pipeline->failure = 'compilación frontend';
        try {
            $pipeline->complete(base_path());
            $this->fail('No se detuvo el pipeline.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('compilación frontend', $exception->getMessage());
        }
        $this->assertCount(3, $pipeline->stages);
        $this->assertArrayNotHasKey('migraciones', $pipeline->stages);
        $this->assertArrayNotHasKey('apertura del servicio', $pipeline->stages);
    }

    public function test_no_habilita_actualizaciones_sin_reinicio_supervisado_valido(): void
    {
        foreach ([null, [], 'reiniciar', [null], ['']] as $command) {
            config(['deployment.restart_command' => $command]);
            $this->assertFalse((new RepositoryDeploymentService)->isConfigured());
        }
    }
}
