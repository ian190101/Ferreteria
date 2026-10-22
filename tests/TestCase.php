<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $connection = $app['db']->connection();

        // Se verifica la conexión resuelta antes de ejecutar los traits destructivos.
        if (! $app->environment('testing')
            || $connection->getDriverName() !== 'mysql'
            || $connection->getDatabaseName() !== 'fabrica_calmina_aroma_testing'
            || $connection->getConfig('username') !== 'calmina_test'
            || (string) $connection->getConfig('port') !== '3307') {
            throw new \RuntimeException('Pruebas detenidas: Laravel no utiliza la conexión exclusiva de pruebas. '.json_encode([
                'environment' => $app->environment(),
                'driver' => $connection->getDriverName(),
                'database' => $connection->getDatabaseName(),
                'username' => $connection->getConfig('username'),
                'port' => $connection->getConfig('port'),
            ]));
        }

        return $app;
    }
}
