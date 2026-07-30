<?php

namespace App\Modules\ServiceOrders\Services;

use App\Modules\SystemSuperadmin\Services\ActiveBusinessProfile;

class ServiceOrderWorkflowPolicy
{
    private array $payload;

    public function __construct()
    {
        $this->payload = ActiveBusinessProfile::payload();
    }

    public function servicesEnabled(): bool
    {
        return ActiveBusinessProfile::enabled('services')
            || (bool) data_get($this->payload, 'capabilities.uses_services', false);
    }

    public function ordersEnabled(): bool
    {
        return ActiveBusinessProfile::enabled('service_orders')
            || (bool) data_get($this->payload, 'services.orders_enabled', false)
            || (bool) data_get($this->payload, 'capabilities.uses_service_orders', false);
    }

    public function technicianRequired(): bool
    {
        return (bool) data_get($this->payload, 'services.technician_required', false);
    }

    public function evidenceEnabled(): bool
    {
        return (bool) data_get($this->payload, 'services.evidence_enabled', false);
    }

    public function warrantyEnabled(): bool
    {
        return (bool) data_get($this->payload, 'services.warranty_enabled', false);
    }

    public function signatureEnabled(): bool
    {
        return (bool) data_get($this->payload, 'services.signature_enabled', false);
    }

    public function customerRequired(): bool
    {
        return (bool) data_get($this->payload, 'capabilities.requires_customer', false)
            || in_array(data_get($this->payload, 'contacts.customer_mode'), ['required'], true);
    }

    public function allowsNegativeStock(): bool
    {
        return (bool) data_get($this->payload, 'sales.allow_negative_stock', false)
            || data_get($this->payload, 'sales.negative_stock_policy') === 'global';
    }

    public function summary(): array
    {
        return [
            'servicesEnabled' => $this->servicesEnabled(),
            'ordersEnabled' => $this->ordersEnabled(),
            'technicianRequired' => $this->technicianRequired(),
            'evidenceEnabled' => $this->evidenceEnabled(),
            'warrantyEnabled' => $this->warrantyEnabled(),
            'signatureEnabled' => $this->signatureEnabled(),
            'customerRequired' => $this->customerRequired(),
            'allowsNegativeStock' => $this->allowsNegativeStock(),
            'mode' => (string) data_get($this->payload, 'services.mode', 'disabled'),
        ];
    }
}
