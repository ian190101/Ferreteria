<?php

namespace App\Modules\Reservations\Services;

use App\Modules\Reservations\Models\BusinessReservation;
use App\Modules\SystemSuperadmin\Models\ReservableResource;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Validation\ValidationException;

class ReservationAvailabilityService
{
    public function ensureResourceAvailable(
        ?int $resourceId,
        CarbonInterface $startAt,
        CarbonInterface $endAt,
        ?int $ignoreReservationId = null
    ): void {
        if ($endAt->lessThanOrEqualTo($startAt)) {
            throw ValidationException::withMessages([
                'end_at' => 'La fecha de fin o devolucion debe ser posterior a la fecha de inicio.',
            ]);
        }

        if (! $resourceId) {
            return;
        }

        $resource = ReservableResource::query()->findOrFail($resourceId);
        if (! $resource->is_active) {
            throw ValidationException::withMessages([
                'reservable_resource_id' => 'El recurso seleccionado esta inactivo y no se puede reservar.',
            ]);
        }

        if ($resource->status !== 'available') {
            throw ValidationException::withMessages([
                'reservable_resource_id' => 'El recurso no esta disponible operativamente.',
            ]);
        }

        $this->ensureOutsideMaintenanceWindow($resource, $startAt, $endAt);
        $this->ensureRulesAllowReservation($resource, $startAt, $endAt);

        $rules = $resource->availability_rules ?? [];
        $bufferMinutes = max(0, (int) data_get($rules, 'buffer_minutes', 0));
        $parallelCapacity = max(1, (int) data_get($rules, 'parallel_capacity', 1));
        $blockedStart = $startAt->copy()->subMinutes($bufferMinutes);
        $blockedEnd = $endAt->copy()->addMinutes($bufferMinutes);

        $overlapExists = BusinessReservation::query()
            ->where('reservable_resource_id', $resourceId)
            ->whereNotIn('status', [
                BusinessReservation::STATUS_CANCELLED,
                BusinessReservation::STATUS_COMPLETED,
                BusinessReservation::STATUS_NO_SHOW,
            ])
            ->when($ignoreReservationId, fn ($query) => $query->whereKeyNot($ignoreReservationId))
            ->where('start_at', '<', $blockedEnd)
            ->where('end_at', '>', $blockedStart)
            ->count();

        if ($overlapExists >= $parallelCapacity) {
            throw ValidationException::withMessages([
                'reservable_resource_id' => 'El recurso ya tiene una reserva o alquiler en ese horario.',
            ]);
        }
    }

    private function ensureOutsideMaintenanceWindow(ReservableResource $resource, CarbonInterface $startAt, CarbonInterface $endAt): void
    {
        if (! $resource->maintenance_starts_at || ! $resource->maintenance_ends_at) {
            return;
        }

        if ($startAt->lessThan($resource->maintenance_ends_at) && $endAt->greaterThan($resource->maintenance_starts_at)) {
            throw ValidationException::withMessages([
                'reservable_resource_id' => 'El recurso esta en mantenimiento dentro de ese horario.',
            ]);
        }
    }

    private function ensureRulesAllowReservation(ReservableResource $resource, CarbonInterface $startAt, CarbonInterface $endAt): void
    {
        $rules = $resource->availability_rules ?? [];

        if ($rules === []) {
            return;
        }

        $duration = $startAt->diffInMinutes($endAt);
        $minMinutes = data_get($rules, 'min_minutes');
        $maxMinutes = data_get($rules, 'max_minutes');

        if ($minMinutes !== null && $duration < (int) $minMinutes) {
            throw ValidationException::withMessages([
                'end_at' => 'La duracion es menor al minimo configurado para el recurso.',
            ]);
        }

        if ($maxMinutes !== null && $duration > (int) $maxMinutes) {
            throw ValidationException::withMessages([
                'end_at' => 'La duracion supera el maximo configurado para el recurso.',
            ]);
        }

        $this->ensureAllowedDays($rules, $startAt, $endAt);
        $this->ensureAllowedTimeRanges($rules, $startAt, $endAt);
    }

    /**
     * @param array<string, mixed> $rules
     */
    private function ensureAllowedDays(array $rules, CarbonInterface $startAt, CarbonInterface $endAt): void
    {
        $blockedDates = collect(data_get($rules, 'blocked_dates', []))
            ->map(fn ($date) => (string) $date)
            ->filter()
            ->values();
        $allowedDays = collect(data_get($rules, 'days', []))
            ->map(fn ($day) => (int) $day)
            ->filter(fn ($day) => $day >= 1 && $day <= 7)
            ->values();

        if ($blockedDates->isEmpty() && $allowedDays->isEmpty()) {
            return;
        }

        foreach (CarbonPeriod::create($startAt->copy()->startOfDay(), $endAt->copy()->startOfDay()) as $date) {
            if ($blockedDates->contains($date->toDateString())) {
                throw ValidationException::withMessages([
                    'start_at' => 'El recurso tiene bloqueada una fecha dentro de ese rango.',
                ]);
            }

            if ($allowedDays->isNotEmpty() && ! $allowedDays->contains((int) $date->dayOfWeekIso)) {
                throw ValidationException::withMessages([
                    'start_at' => 'El recurso no esta disponible ese dia de la semana.',
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $rules
     */
    private function ensureAllowedTimeRanges(array $rules, CarbonInterface $startAt, CarbonInterface $endAt): void
    {
        $timeRanges = collect(data_get($rules, 'time_ranges', []))
            ->filter(fn ($range) => is_array($range) && filled($range['start'] ?? null) && filled($range['end'] ?? null));

        if ($timeRanges->isEmpty() || ! $startAt->isSameDay($endAt)) {
            return;
        }

        $allowed = $timeRanges->contains(function (array $range) use ($startAt, $endAt): bool {
            $rangeStart = Carbon::parse($startAt->toDateString().' '.$range['start']);
            $rangeEnd = Carbon::parse($startAt->toDateString().' '.$range['end']);

            return $startAt->greaterThanOrEqualTo($rangeStart) && $endAt->lessThanOrEqualTo($rangeEnd);
        });

        if (! $allowed) {
            throw ValidationException::withMessages([
                'start_at' => 'El recurso no esta disponible dentro de ese horario.',
            ]);
        }
    }
}
