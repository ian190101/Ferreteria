<?php

namespace App\Modules\Sales\Services;

use App\Modules\SystemSuperadmin\Services\ActiveBusinessProfile;

class DeliveryWorkflowPolicy
{
    public function mode(): string
    {
        return (string) (ActiveBusinessProfile::payload()['deliveries']['mode'] ?? 'optional');
    }

    public function enabled(): bool
    {
        return ActiveBusinessProfile::enabled('deliveries') && $this->mode() !== 'disabled';
    }

    public function required(): bool
    {
        return $this->enabled() && $this->mode() === 'required';
    }

    public function driverRequired(): bool
    {
        return $this->enabled() && (bool) (ActiveBusinessProfile::payload()['deliveries']['driver_required'] ?? false);
    }

    public function truckRequired(): bool
    {
        return $this->enabled() && (bool) (ActiveBusinessProfile::payload()['deliveries']['truck_required'] ?? false);
    }

    public function summary(): array
    {
        return [
            'mode' => $this->mode(),
            'enabled' => $this->enabled(),
            'required' => $this->required(),
            'driverRequired' => $this->driverRequired(),
            'truckRequired' => $this->truckRequired(),
        ];
    }
}
