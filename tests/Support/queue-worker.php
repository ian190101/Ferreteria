<?php

declare(strict_types=1);
use App\Modules\Settings\Services\RecoveryJournal;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Symfony\Component\Console\Input\ArgvInput;

require __DIR__.'/../bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$backupPath = getenv('QA_BACKUP_DISK_PATH');
if ($backupPath !== false && $backupPath !== '') {
    $resolvedBackupPath = realpath($backupPath);
    $allowedBackupRoot = realpath(__DIR__.'/../../storage/framework/testing/disks');
    if (! $resolvedBackupPath || ! $allowedBackupRoot || ! str_starts_with($resolvedBackupPath, $allowedBackupRoot.DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('El disco del trabajador de pruebas debe estar dentro del almacenamiento de pruebas.');
    }
    $app->afterBootstrapping(LoadConfiguration::class, function ($application) use ($resolvedBackupPath): void {
        $application['config']->set('filesystems.disks.local.root', $resolvedBackupPath);
    });
}
$journalPath = getenv('QA_RECOVERY_JOURNAL_PATH');
if ($journalPath !== false && $journalPath !== '') {
    $resolved = realpath($journalPath);
    $allowed = realpath(__DIR__.'/../../storage/qa');
    if (! $resolved || ! $allowed || ! str_starts_with($resolved, $allowed.DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('El diario del trabajador de pruebas debe estar dentro de storage/qa.');
    }
    $app->instance(RecoveryJournal::class, new RecoveryJournal($resolved));
}
exit($app->handleCommand(new ArgvInput));
