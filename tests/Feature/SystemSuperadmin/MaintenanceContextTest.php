<?php

declare(strict_types=1);

use App\Modules\SystemSuperadmin\Models\BusinessProfileSandboxSession;
use App\Modules\SystemSuperadmin\Services\DatabasePurgeService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\MaintenanceScenario;

it('no acepta una vista previa emitida para otra base aunque conserve el mismo contenido', function () {
    $user = MaintenanceScenario::user();
    $sale = MaintenanceScenario::sale($user);
    $options = MaintenanceScenario::purgeOptions($user, ['sales']);
    $envelope = json_decode(Crypt::decryptString($options['preview_token']), true, 512, JSON_THROW_ON_ERROR);
    $envelope['database'] = 'otra_base';
    $options['preview_token'] = Crypt::encryptString(json_encode($envelope, JSON_THROW_ON_ERROR));
    expect(fn () => app(DatabasePurgeService::class)->executePurge($options, $user))->toThrow(ValidationException::class);
    expect(DB::table('sales')->where('id', $sale->id)->exists())->toBeTrue();
});

it('no permite ejecutar la purga deshabilitada con una vista previa anterior', function () {
    $user = MaintenanceScenario::user();
    MaintenanceScenario::sale($user);
    $options = MaintenanceScenario::purgeOptions($user, ['sales']);
    config(['maintenance.purge_enabled' => false]);
    $preview = app(DatabasePurgeService::class)->preview(['scope' => 'selected', 'tables' => ['sales']], $user);
    expect($preview['can_execute'])->toBeFalse();
    $this->actingAs($user)->post(route('system-superadmin.database-purge.execute'), [...$options, 'confirmation' => 'BORRAR'])->assertStatus(423);
    expect(DB::table('sales')->count())->toBe(1);
});

it('mantiene las herramientas destructivas fuera de la demo completa', function () {
    $user = MaintenanceScenario::user();
    $session = BusinessProfileSandboxSession::query()->create(['user_id' => $user->id, 'name' => 'Demo QA', 'payload' => [],
        'status' => 'active', 'expires_at' => now()->addHour(), 'last_activity_at' => now()]);
    foreach (['system-superadmin.database-purge.index', 'system-superadmin.git-versioning.index', 'settings.system.index'] as $route) {
        $this->actingAs($user)->withSession(['business_full_sandbox_id' => $session->id])->get(route($route))
            ->assertRedirect(route('dashboard'));
    }
});
