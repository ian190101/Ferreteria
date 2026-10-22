<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabaseState;

abstract class CommittedDatabaseTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Las conexiones ven datos confirmados y la limpieza no depende de down históricos.
        $this->artisan('migrate:fresh')->assertExitCode(0);
        RefreshDatabaseState::$migrated = false;
        $this->beforeApplicationDestroyed(function (): void {
            try {
                $this->artisan('db:wipe', ['--force' => true])->assertExitCode(0);
            } finally {
                RefreshDatabaseState::$migrated = false;
            }
        });
    }
}
