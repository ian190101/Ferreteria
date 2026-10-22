<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('detiene el arranque antes de Laravel cuando una variable apunta fuera del entorno de pruebas', function (string $name, string $value) {
    $environment = [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '3307', 'DB_DATABASE' => 'fabrica_calmina_aroma_testing',
        'DB_USERNAME' => 'calmina_test', 'DB_URL' => '', 'DB_SOCKET' => '',
    ];
    $environment[$name] = $value;
    $process = new Process([PHP_BINARY, dirname(__DIR__).'/bootstrap.php'], null, $environment);
    $process->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput().$process->getOutput())->toContain('configuración insegura de '.$name);
})->with([
    'entorno operativo' => ['APP_ENV', 'production'],
    'otro motor' => ['DB_CONNECTION', 'sqlite'],
    'servidor remoto' => ['DB_HOST', 'produccion.example'],
    'puerto operativo' => ['DB_PORT', '3306'],
    'base operativa' => ['DB_DATABASE', 'fabrica_calmina_aroma'],
    'usuario privilegiado' => ['DB_USERNAME', 'root'],
    'URL que reemplaza la conexión' => ['DB_URL', 'mysql://root@localhost/operativa'],
    'socket operativo' => ['DB_SOCKET', '/var/run/mysqld/mysqld.sock'],
]);
