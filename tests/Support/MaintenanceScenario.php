<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Sales\Models\DocumentSequence;
use App\Modules\Sales\Models\Sale;
use App\Modules\SystemSuperadmin\Services\DatabasePurgeService;
use App\Support\SystemRoles;
use Spatie\Permission\Models\Role;

final class MaintenanceScenario
{
    public static function user(): User
    {
        $branch = Branch::query()->create(['name' => 'Mantenimiento QA', 'code' => 'QA-MAINT', 'barcode' => 'QA-MAINT', 'is_active' => true]);
        $user = User::factory()->create(['branch_id' => $branch->id, 'email_verified_at' => now()]);
        $user->assignRole(Role::findOrCreate(SystemRoles::SYSTEM_SUPERADMIN, 'web'));

        return $user;
    }

    public static function sequence(int $branchId, string $prefix = 'QA-NV-'): DocumentSequence
    {
        return DocumentSequence::query()->create(['branch_id' => $branchId, 'document_type' => 'sale_note',
            'name' => 'Notas QA', 'prefix' => $prefix, 'next_number' => 17, 'padding' => 6, 'is_active' => true]);
    }

    public static function sale(User $user, string $number = 'QA-NV-000001', ?int $branchId = null): Sale
    {
        return Sale::query()->create(['branch_id' => $branchId ?? $user->branch_id, 'user_id' => $user->id,
            'receipt_number' => $number, 'document_type' => 'sale_note', 'sold_at' => now(),
            'subtotal' => 100, 'total' => 100, 'balance_due' => 100, 'status' => 'issued']);
    }

    public static function purgeOptions(User $user, array $tables, array $extra = []): array
    {
        $options = ['scope' => 'selected', 'tables' => $tables, 'create_backup' => false, ...$extra];

        return [...$options, 'preview_token' => app(DatabasePurgeService::class)->preview($options, $user)['preview_token']];
    }
}
