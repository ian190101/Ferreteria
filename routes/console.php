<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Support\AuthSessionCache;
use App\Support\SystemRoles;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('system:create-master-user {email} {password} {--name=Mr. Robot Bolivia}', function (string $email, string $password) {
    $branch = Branch::query()->orderBy('id')->first()
        ?? Branch::query()->create([
            'name' => 'Sucursal Principal',
            'code' => 'PRINCIPAL',
            'barcode' => 'BR-PRINCIPAL',
            'phone' => null,
            'address' => null,
            'is_active' => true,
        ]);

    $role = Role::firstOrCreate(['name' => SystemRoles::SYSTEM_SUPERADMIN, 'guard_name' => 'web']);
    $role->syncPermissions(Permission::query()->where('guard_name', 'web')->pluck('name')->all());

    $user = User::query()->updateOrCreate(
        ['email' => $email],
        [
            'branch_id' => $branch->id,
            'name' => (string) $this->option('name'),
            'is_active' => true,
            'password' => Hash::make($password),
            'force_password_change' => false,
        ],
    );

    $user->syncRoles([$role->name]);
    $user->accessibleBranches()->syncWithoutDetaching([$branch->id]);
    AuthSessionCache::bump();

    $this->info("Usuario maestro {$email} preparado correctamente.");

    return Command::SUCCESS;
})->purpose('Crea o actualiza el usuario interno sistemasuperadmin.');
