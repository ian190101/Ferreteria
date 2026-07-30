<?php

namespace App\Modules\Reservations\Services;

use App\Modules\Reservations\Models\BusinessReservation;
use App\Modules\SystemSuperadmin\Models\ReservableResource;
use Carbon\CarbonInterface;
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

        $overlapExists = BusinessReservation::query()
            ->where('reservable_resource_id', $resourceId)
            ->whereNotIn('status', [
                BusinessReservation::STATUS_CANCELLED,
                BusinessReservation::STATUS_COMPLETED,
                BusinessReservation::STATUS_NO_SHOW,
            ])
            ->when($ignoreReservationId, fn ($query) => $query->whereKeyNot($ignoreReservationId))
            ->where(function ($query) use ($startAt, $endAt) {
                $query
                    ->whereBetween('start_at', [$startAt, $endAt])
                    ->orWhereBetween('end_at', [$startAt, $endAt])
                    ->orWhere(fn ($inside) => $inside
                        ->where('start_at', '<=', $startAt)
                        ->where('end_at', '>=', $endAt));
            })
            ->exists();

        if ($overlapExists) {
            throw ValidationException::withMessages([
                'reservable_resource_id' => 'El recurso ya tiene una reserva o alquiler en ese horario.',
            ]);
        }
    }
}
