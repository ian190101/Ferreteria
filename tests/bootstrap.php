<?php

declare(strict_types=1);

// Se comprueba antes de iniciar Laravel: RefreshDatabase puede borrar tablas.
$expected = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3307',
    'DB_DATABASE' => 'fabrica_calmina_aroma_testing',
    'DB_USERNAME' => 'calmina_test',
    'DB_URL' => '',
    'DB_SOCKET' => '',
];

foreach ($expected as $name => $value) {
    if (($_ENV[$name] ?? getenv($name)) !== $value) {
        throw new RuntimeException("Pruebas detenidas: configuración insegura de {$name}.");
    }
    // En Windows pueden existir valores heredados diferentes en $_SERVER.
    $_SERVER[$name] = $value;
    $_ENV[$name] = $value;
    putenv($name.'='.$value);
}

foreach (['APP_KEY', 'APP_MAINTENANCE_DRIVER', 'APP_MAINTENANCE_STORE', 'RESTORE_DB_HOST', 'RESTORE_DB_PORT', 'RESTORE_DB_DATABASE', 'RESTORE_DB_USERNAME', 'RESTORE_DB_PASSWORD', 'MAINTENANCE_QUEUE_CONNECTION', 'MAINTENANCE_PURGE_ENABLED', 'MAINTENANCE_GIT_UPDATES_ENABLED'] as $name) {
    if (array_key_exists($name, $_ENV)) {
        $_SERVER[$name] = $_ENV[$name];
        putenv($name.'='.$_ENV[$name]);
    }
}

$configCache = $_ENV['APP_CONFIG_CACHE'] ?? getenv('APP_CONFIG_CACHE') ?: dirname(__DIR__).'/bootstrap/cache/config.php';
if (is_file($configCache)) {
    throw new RuntimeException('Pruebas detenidas: existe configuración cacheada. Retírela antes de usar la base de pruebas.');
}

require dirname(__DIR__).'/vendor/autoload.php';
