<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Printing\Models\PrintDocumentTemplate;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Models\PrintRule;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Models\PrinterProfile;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use App\Support\SystemCacheInvalidator;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function printingUser(array $permissions, bool $enabled = true): User
{
    BusinessProfile::query()->update(['status' => 'archived']);
    BusinessProfile::query()->create([
        'name' => 'Perfil test impresion',
        'business_type' => 'mixed',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'capabilities' => ['uses_printer_profiles' => $enabled],
        ]),
        'applied_at' => now(),
    ]);
    SystemCacheInvalidator::bumpOperational();

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'impresion-test', 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $suffix = uniqid();
    $branch = Branch::query()->create([
        'name' => 'Sucursal impresion',
        'code' => 'PRINT-'.$suffix,
        'barcode' => 'BR-PRINT-'.$suffix,
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);

    $user->assignRole($role);
    $user->accessibleBranches()->sync([$branch->id]);

    return $user;
}

it('muestra el modulo de impresion cuando la capacidad esta activa', function () {
    $user = printingUser(['printing.view'], true);

    $this->actingAs($user)
        ->get(route('printing.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Printing/Index', false)
            ->has('documentTypes')
            ->has('areas')
        );
});

it('bloquea el modulo de impresion si el perfil no lo permite', function () {
    $user = printingUser(['printing.view'], false);

    $this->actingAs($user)
        ->get(route('printing.index'))
        ->assertNotFound();
});

it('crea impresora plantilla regla y trabajo de impresion', function () {
    $user = printingUser(['printing.view', 'printing.manage', 'printing.jobs.manage'], true);

    $this->actingAs($user)
        ->post(route('printing.printers.store'), [
            'branch_id' => $user->branch_id,
            'code' => 'CAJA-01',
            'name' => 'Caja principal',
            'area' => 'cashier',
            'paper_type' => 'thermal_80',
            'thermal_width_mm' => 80,
            'copies' => 1,
            'auto_print' => true,
        ])
        ->assertRedirect();

    $printer = PrinterProfile::query()->where('code', 'CAJA-01')->firstOrFail();

    $this->actingAs($user)
        ->post(route('printing.templates.store'), [
            'branch_id' => $user->branch_id,
            'document_type' => 'ticket_pos',
            'name' => 'Ticket principal',
            'paper_type' => 'thermal_80',
            'thermal_width_mm' => 80,
            'font_size' => 10,
            'margin_mm' => 3,
            'show_logo' => false,
            'show_barcode' => false,
            'color' => '#000000',
            'fields' => ['empresa', 'numero', 'cliente', 'items', 'total', 'metodo_pago'],
            'is_default' => true,
            'is_active' => true,
        ])
        ->assertRedirect();

    $template = PrintDocumentTemplate::query()->where('name', 'Ticket principal')->firstOrFail();

    $this->actingAs($user)
        ->post(route('printing.rules.store'), [
            'branch_id' => $user->branch_id,
            'printer_profile_id' => $printer->id,
            'print_document_template_id' => $template->id,
            'document_type' => 'ticket_pos',
            'area' => 'cashier',
            'trigger' => 'sale_paid',
            'copies' => 1,
            'auto_print' => true,
            'is_active' => true,
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('printing.jobs.store'), [
            'branch_id' => $user->branch_id,
            'printer_profile_id' => $printer->id,
            'print_document_template_id' => $template->id,
            'document_type' => 'ticket_pos',
            'area' => 'cashier',
            'copies' => 1,
        ])
        ->assertRedirect();

    expect(PrinterProfile::query()->count())->toBe(1)
        ->and(PrintDocumentTemplate::query()->count())->toBe(1)
        ->and(PrintRule::query()->count())->toBe(1)
        ->and(PrintJob::query()->where('status', PrintJob::STATUS_QUEUED)->exists())->toBeTrue()
        ->and(PrintJob::query()->first()->rendered_preview)->toContain('Ticket POS');
});

it('marca un trabajo de impresion como impreso', function () {
    $user = printingUser(['printing.view', 'printing.jobs.manage'], true);
    $job = PrintJob::query()->create([
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'document_type' => 'ticket_pos',
        'area' => 'cashier',
        'status' => PrintJob::STATUS_QUEUED,
        'copies' => 1,
    ]);

    $this->actingAs($user)
        ->patch(route('printing.jobs.printed', $job))
        ->assertRedirect();

    expect($job->fresh()->status)->toBe(PrintJob::STATUS_PRINTED)
        ->and($job->fresh()->printed_at)->not->toBeNull();
});
