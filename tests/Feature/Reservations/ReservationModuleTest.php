<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Customers\Models\Customer;
use App\Modules\HumanResources\Models\Worker;
use App\Modules\Reservations\Models\BusinessReservation;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Models\BusinessStateDefinition;
use App\Modules\SystemSuperadmin\Models\ReservableResource;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use App\Support\SystemCacheInvalidator;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function reservationConfiguration(array $overrides = []): array
{
    return array_replace_recursive([
        'identity' => ['business_type' => 'rentals'],
        'modules' => [
            'reservations' => true,
            'rentals' => true,
            'customers' => true,
            'workers' => true,
        ],
        'capabilities' => [
            'uses_reservations' => true,
            'uses_reservable_resources' => true,
            'uses_rentals' => true,
            'requires_customer' => true,
            'uses_custom_statuses' => true,
            'requires_cash_session' => false,
        ],
        'reservations' => [
            'calendar_enabled' => true,
            'resource_required' => true,
            'advance_mode' => 'optional',
            'confirmation_enabled' => true,
            'reminders_enabled' => false,
            'allowed_channels' => ['mostrador', 'telefono', 'whatsapp'],
            'appointment_kinds' => ['cita', 'reserva', 'servicio'],
        ],
        'rentals' => [
            'enabled' => true,
            'deposit_required' => true,
            'late_penalty_enabled' => true,
            'condition_check_required' => true,
        ],
    ], $overrides);
}

function reservationUser(array $configuration = []): User
{
    BusinessProfile::query()->update(['status' => 'archived']);
    BusinessProfile::query()->create([
        'name' => 'Perfil reservas prueba',
        'business_type' => data_get($configuration, 'identity.business_type', 'rentals'),
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized($configuration),
        'applied_at' => now(),
    ]);
    SystemCacheInvalidator::bumpOperational();

    foreach (['reservations.view', 'reservations.manage', 'rentals.view', 'rentals.manage'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'reservas-test', 'guard_name' => 'web']);
    $role->syncPermissions(['reservations.view', 'reservations.manage', 'rentals.view', 'rentals.manage']);

    $branch = Branch::query()->create([
        'name' => 'Sucursal Reservas',
        'code' => 'RES',
        'barcode' => 'BR-RES',
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

function reservableResourceFor(Branch|int $branch, array $attributes = []): ReservableResource
{
    $branchId = $branch instanceof Branch ? $branch->id : $branch;

    return ReservableResource::query()->create(array_merge([
        'branch_id' => $branchId,
        'type' => 'equipo',
        'code' => 'REC-'.fake()->unique()->numerify('###'),
        'name' => 'Equipo reservable',
        'capacity' => 1,
        'availability_rules' => [],
        'metadata' => [],
        'is_active' => true,
    ], $attributes));
}

it('muestra reservas y alquileres cuando el perfil activa recursos reservables', function () {
    $user = reservationUser(reservationConfiguration());
    reservableResourceFor($user->branch_id, ['name' => 'Salon principal', 'type' => 'salon', 'capacity' => 30]);

    $this->actingAs($user)
        ->get(route('reservations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reservations/Index', false)
            ->where('policy.reservationsEnabled', true)
            ->where('policy.rentalsEnabled', true)
            ->where('resources.0.name', 'Salon principal'));
});

it('gestiona citas con confirmacion recordatorio no asistencia y reprogramacion', function () {
    $user = reservationUser(reservationConfiguration([
        'reservations' => [
            'reminders_enabled' => true,
            'appointment_kinds' => ['consulta', 'servicio'],
            'allowed_channels' => ['telefono', 'whatsapp'],
        ],
        'rentals' => ['deposit_required' => false, 'condition_check_required' => false],
    ]));
    $customer = Customer::query()->create(['name' => 'Paciente agenda', 'phone' => '70000002', 'is_active' => true]);
    $resource = reservableResourceFor($user->branch_id, ['name' => 'Consultorio 1', 'type' => 'consultorio']);

    $this->actingAs($user)
        ->post(route('reservations.store'), [
            'branch_id' => $user->branch_id,
            'customer_id' => $customer->id,
            'reservable_resource_id' => $resource->id,
            'type' => BusinessReservation::TYPE_RESERVATION,
            'appointment_kind' => 'consulta',
            'channel' => 'whatsapp',
            'title' => 'Consulta dental',
            'start_at' => '2026-08-06 10:00:00',
            'end_at' => '2026-08-06 10:45:00',
            'reminder_at' => '2026-08-06 09:00:00',
            'amount' => 150,
        ])
        ->assertRedirect();

    $reservation = BusinessReservation::query()->firstOrFail();
    expect($reservation->appointment_kind)->toBe('consulta')
        ->and($reservation->confirmation_status)->toBe(BusinessReservation::CONFIRMATION_PENDING)
        ->and($reservation->reminder_at?->format('Y-m-d H:i:s'))->toBe('2026-08-06 09:00:00');

    $this->actingAs($user)
        ->patch(route('reservations.status', $reservation), [
            'status' => BusinessReservation::STATUS_CONFIRMED,
            'confirmation_status' => BusinessReservation::CONFIRMATION_CONFIRMED,
        ])
        ->assertRedirect();

    expect($reservation->fresh()->confirmed_at)->not->toBeNull();

    $this->actingAs($user)
        ->patch(route('reservations.schedule', $reservation), [
            'reservable_resource_id' => $resource->id,
            'start_at' => '2026-08-06 11:00:00',
            'end_at' => '2026-08-06 11:45:00',
            'reminder_at' => '2026-08-06 10:30:00',
            'notes' => 'Cliente pidio mover la cita.',
        ])
        ->assertRedirect();

    expect($reservation->fresh()->status)->toBe(BusinessReservation::STATUS_RESCHEDULED)
        ->and($reservation->fresh()->rescheduled_at)->not->toBeNull()
        ->and($reservation->fresh()->start_at?->format('Y-m-d H:i:s'))->toBe('2026-08-06 11:00:00');

    $this->actingAs($user)
        ->patch(route('reservations.status', $reservation), ['status' => BusinessReservation::STATUS_NO_SHOW])
        ->assertRedirect();

    expect($reservation->fresh()->no_show_at)->not->toBeNull();
});

it('bloquea canales tipos de cita y recordatorios no permitidos por perfil', function () {
    $user = reservationUser(reservationConfiguration([
        'reservations' => [
            'reminders_enabled' => false,
            'appointment_kinds' => ['servicio'],
            'allowed_channels' => ['telefono'],
        ],
        'rentals' => ['deposit_required' => false, 'condition_check_required' => false],
    ]));
    $resource = reservableResourceFor($user->branch_id, ['type' => 'box']);

    $this->actingAs($user)
        ->post(route('reservations.store'), [
            'branch_id' => $user->branch_id,
            'reservable_resource_id' => $resource->id,
            'type' => BusinessReservation::TYPE_RESERVATION,
            'appointment_kind' => 'consulta',
            'channel' => 'instagram',
            'customer_name' => 'Cliente agenda',
            'title' => 'Cita restringida',
            'start_at' => '2026-08-07 10:00:00',
            'end_at' => '2026-08-07 10:30:00',
            'reminder_at' => '2026-08-07 09:00:00',
        ])
        ->assertSessionHasErrors(['appointment_kind', 'channel', 'reminder_at']);
});

it('bloquea reservas operativas cuando las capacidades estan desactivadas', function () {
    $user = reservationUser([
        'identity' => ['business_type' => 'hardware_store'],
        'modules' => ['reservations' => true, 'rentals' => false],
        'capabilities' => [
            'uses_reservations' => false,
            'uses_reservable_resources' => false,
            'uses_rentals' => false,
        ],
    ]);

    $this->actingAs($user)
        ->get(route('reservations.index'))
        ->assertNotFound();
});

it('registra reserva y bloquea solapamientos del mismo recurso', function () {
    $user = reservationUser(reservationConfiguration([
        'rentals' => ['deposit_required' => false, 'condition_check_required' => false],
    ]));
    $customer = Customer::query()->create(['name' => 'Cliente reserva', 'phone' => '70000000', 'is_active' => true]);
    $resource = reservableResourceFor($user->branch_id, ['name' => 'Cancha 1', 'type' => 'cancha']);

    $payload = [
        'branch_id' => $user->branch_id,
        'customer_id' => $customer->id,
        'reservable_resource_id' => $resource->id,
        'type' => BusinessReservation::TYPE_RESERVATION,
        'title' => 'Reserva de cancha',
        'start_at' => '2026-08-01 10:00:00',
        'end_at' => '2026-08-01 11:00:00',
        'amount' => 120,
        'advance_amount' => 30,
    ];

    $this->actingAs($user)
        ->post(route('reservations.store'), $payload)
        ->assertRedirect();

    expect(BusinessReservation::query()->count())->toBe(1)
        ->and(BusinessReservation::query()->first()->total_amount)->toBe('120.0000');

    $this->actingAs($user)
        ->post(route('reservations.store'), [
            ...$payload,
            'title' => 'Reserva solapada',
            'start_at' => '2026-08-01 10:30:00',
            'end_at' => '2026-08-01 11:30:00',
        ])
        ->assertSessionHasErrors('reservable_resource_id');
});

it('respeta disponibilidad avanzada, mantenimiento y recursos globales', function () {
    $user = reservationUser(reservationConfiguration([
        'rentals' => ['deposit_required' => false, 'condition_check_required' => false],
    ]));
    $customer = Customer::query()->create(['name' => 'Cliente agenda', 'phone' => '70000001', 'is_active' => true]);
    $resource = ReservableResource::query()->create([
        'branch_id' => null,
        'type' => 'room',
        'code' => 'GLOBAL-01',
        'name' => 'Consultorio global',
        'capacity' => 1,
        'status' => 'available',
        'maintenance_starts_at' => '2026-08-03 12:00:00',
        'maintenance_ends_at' => '2026-08-03 13:00:00',
        'availability_rules' => [
            'days' => [1, 2, 3, 4, 5],
            'time_ranges' => [['start' => '08:00', 'end' => '18:00']],
            'buffer_minutes' => 15,
        ],
        'is_active' => true,
    ]);

    $basePayload = [
        'branch_id' => $user->branch_id,
        'customer_id' => $customer->id,
        'reservable_resource_id' => $resource->id,
        'type' => BusinessReservation::TYPE_RESERVATION,
        'title' => 'Reserva global',
        'amount' => 100,
    ];

    $this->actingAs($user)
        ->post(route('reservations.store'), [
            ...$basePayload,
            'start_at' => '2026-08-03 09:00:00',
            'end_at' => '2026-08-03 10:00:00',
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('reservations.store'), [
            ...$basePayload,
            'title' => 'Reserva con buffer',
            'start_at' => '2026-08-03 10:05:00',
            'end_at' => '2026-08-03 11:00:00',
        ])
        ->assertSessionHasErrors('reservable_resource_id');

    $this->actingAs($user)
        ->post(route('reservations.store'), [
            ...$basePayload,
            'title' => 'Reserva fuera de horario',
            'start_at' => '2026-08-03 19:00:00',
            'end_at' => '2026-08-03 20:00:00',
        ])
        ->assertSessionHasErrors('start_at');

    $this->actingAs($user)
        ->post(route('reservations.store'), [
            ...$basePayload,
            'title' => 'Reserva en mantenimiento',
            'start_at' => '2026-08-03 12:15:00',
            'end_at' => '2026-08-03 12:45:00',
        ])
        ->assertSessionHasErrors('reservable_resource_id');
});

it('permite capacidad paralela cuando el recurso lo configura explicitamente', function () {
    $user = reservationUser(reservationConfiguration([
        'rentals' => ['deposit_required' => false, 'condition_check_required' => false],
    ]));
    $resource = reservableResourceFor($user->branch_id, [
        'name' => 'Salon compartido',
        'type' => 'hall',
        'availability_rules' => ['parallel_capacity' => 2],
    ]);

    $payload = [
        'branch_id' => $user->branch_id,
        'reservable_resource_id' => $resource->id,
        'type' => BusinessReservation::TYPE_RESERVATION,
        'start_at' => '2026-08-05 10:00:00',
        'end_at' => '2026-08-05 11:00:00',
        'amount' => 80,
    ];

    $this->actingAs($user)
        ->post(route('reservations.store'), [...$payload, 'customer_name' => 'Cliente A', 'title' => 'Evento A'])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('reservations.store'), [...$payload, 'customer_name' => 'Cliente B', 'title' => 'Evento B'])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('reservations.store'), [...$payload, 'customer_name' => 'Cliente C', 'title' => 'Evento C'])
        ->assertSessionHasErrors('reservable_resource_id');
});

it('registra alquiler con garantia y marca salida y devolucion', function () {
    $user = reservationUser(reservationConfiguration());
    $resource = reservableResourceFor($user->branch_id, ['name' => 'Taladro demo', 'type' => 'herramienta']);
    $worker = Worker::query()->create([
        'branch_id' => $user->branch_id,
        'name' => 'Responsable alquiler',
        'position' => 'Encargado',
        'salary_amount' => 0,
        'salary_frequency' => 'monthly',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('reservations.store'), [
            'branch_id' => $user->branch_id,
            'reservable_resource_id' => $resource->id,
            'worker_id' => $worker->id,
            'type' => BusinessReservation::TYPE_RENTAL,
            'customer_name' => 'Cliente alquiler',
            'customer_phone' => '71111111',
            'title' => 'Alquiler de taladro',
            'start_at' => '2026-08-02 09:00:00',
            'end_at' => '2026-08-03 09:00:00',
            'amount' => 80,
            'advance_amount' => 20,
            'deposit_amount' => 200,
            'penalty_amount' => 10,
            'condition_before' => 'Equipo funcionando con maletin completo.',
        ])
        ->assertRedirect();

    $rental = BusinessReservation::query()->firstOrFail();
    expect($rental->type)->toBe(BusinessReservation::TYPE_RENTAL)
        ->and($rental->total_amount)->toBe('290.0000');

    $this->actingAs($user)
        ->patch(route('reservations.status', $rental), ['status' => BusinessReservation::STATUS_IN_PROGRESS])
        ->assertRedirect();

    expect($rental->fresh()->checked_out_at)->not->toBeNull();

    $this->actingAs($user)
        ->patch(route('reservations.status', $rental), [
            'status' => BusinessReservation::STATUS_COMPLETED,
            'condition_after' => 'Equipo devuelto sin danos.',
        ])
        ->assertRedirect();

    expect($rental->fresh()->returned_at)->not->toBeNull()
        ->and($rental->fresh()->condition_after)->toBe('Equipo devuelto sin danos.');
});

it('respeta estados personalizados para reservas', function () {
    $user = reservationUser(reservationConfiguration([
        'rentals' => ['deposit_required' => false, 'condition_check_required' => false],
    ]));
    $resource = reservableResourceFor($user->branch_id);

    BusinessStateDefinition::query()->create([
        'entity_type' => 'reservation',
        'code' => BusinessReservation::STATUS_PENDING,
        'label' => 'Pendiente',
        'is_initial' => true,
        'is_final' => false,
        'allowed_transitions' => [BusinessReservation::STATUS_CONFIRMED],
        'sort_order' => 1,
        'is_active' => true,
    ]);
    BusinessStateDefinition::query()->create([
        'entity_type' => 'reservation',
        'code' => BusinessReservation::STATUS_CONFIRMED,
        'label' => 'Confirmada',
        'is_initial' => false,
        'is_final' => false,
        'allowed_transitions' => [],
        'sort_order' => 2,
        'is_active' => true,
    ]);

    $reservation = BusinessReservation::query()->create([
        'branch_id' => $user->branch_id,
        'reservable_resource_id' => $resource->id,
        'user_id' => $user->id,
        'reservation_number' => 'RES-TEST-001',
        'type' => BusinessReservation::TYPE_RESERVATION,
        'status' => BusinessReservation::STATUS_PENDING,
        'title' => 'Reserva con estado',
        'start_at' => '2026-08-04 10:00:00',
        'end_at' => '2026-08-04 11:00:00',
    ]);

    $this->actingAs($user)
        ->patch(route('reservations.status', $reservation), ['status' => BusinessReservation::STATUS_COMPLETED])
        ->assertSessionHasErrors('status');

    $this->actingAs($user)
        ->patch(route('reservations.status', $reservation), ['status' => BusinessReservation::STATUS_CONFIRMED])
        ->assertRedirect();

    expect($reservation->fresh()->status)->toBe(BusinessReservation::STATUS_CONFIRMED);
});
