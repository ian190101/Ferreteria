<?php

namespace App\Modules\Reservations\Services;

use App\Modules\SystemSuperadmin\Services\ActiveBusinessProfile;

class ReservationWorkflowPolicy
{
    public function reservationsEnabled(): bool
    {
        return ActiveBusinessProfile::capable('uses_reservations')
            || ActiveBusinessProfile::capable('uses_reservable_resources')
            || ActiveBusinessProfile::capable('uses_rentals');
    }

    public function rentalsEnabled(): bool
    {
        $payload = ActiveBusinessProfile::payload();

        return ActiveBusinessProfile::capable('uses_rentals')
            || (bool) data_get($payload, 'rentals.enabled', false);
    }

    public function resourceRequired(string $type = 'reservation'): bool
    {
        $payload = ActiveBusinessProfile::payload();

        if ($type === 'rental') {
            return true;
        }

        return (bool) data_get($payload, 'reservations.resource_required', false);
    }

    public function customerRequired(): bool
    {
        return ActiveBusinessProfile::capable('requires_customer')
            || data_get(ActiveBusinessProfile::payload(), 'contacts.customer_mode') === 'required';
    }

    public function advanceEnabled(): bool
    {
        return data_get(ActiveBusinessProfile::payload(), 'reservations.advance_mode', 'optional') !== 'disabled';
    }

    public function depositRequired(): bool
    {
        return (bool) data_get(ActiveBusinessProfile::payload(), 'rentals.deposit_required', false);
    }

    public function penaltyEnabled(): bool
    {
        return (bool) data_get(ActiveBusinessProfile::payload(), 'rentals.late_penalty_enabled', false);
    }

    public function conditionCheckRequired(string $type = 'reservation'): bool
    {
        return $type === 'rental'
            && (bool) data_get(ActiveBusinessProfile::payload(), 'rentals.condition_check_required', false);
    }

    public function summary(): array
    {
        return [
            'reservationsEnabled' => $this->reservationsEnabled(),
            'rentalsEnabled' => $this->rentalsEnabled(),
            'customerRequired' => $this->customerRequired(),
            'advanceEnabled' => $this->advanceEnabled(),
            'depositRequired' => $this->depositRequired(),
            'penaltyEnabled' => $this->penaltyEnabled(),
            'reservationResourceRequired' => $this->resourceRequired('reservation'),
            'rentalResourceRequired' => $this->resourceRequired('rental'),
        ];
    }
}
