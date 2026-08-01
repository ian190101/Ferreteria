<?php

namespace App\Modules\SystemSuperadmin\Services;

class BusinessProfileCompatibilityValidator
{
    public function validate(string $businessType, array $configuration): array
    {
        $configuration = BusinessProfileConfiguration::normalized($configuration);
        $modules = $configuration['modules'] ?? [];
        $capabilities = $configuration['capabilities'] ?? [];
        $featureFlags = $configuration['feature_flags'] ?? [];
        $sales = $configuration['sales'] ?? [];
        $billing = $configuration['billing'] ?? [];
        $cash = $configuration['cash'] ?? [];
        $reservations = $configuration['reservations'] ?? [];

        $errors = [];
        $warnings = [];

        $enabled = fn (string $key): bool => (bool) ($capabilities[$key] ?? false);
        $flag = fn (string $key): bool => (bool) ($featureFlags[$key] ?? false);
        $module = fn (string $key): bool => (bool) ($modules[$key] ?? false);

        foreach (BusinessCapabilityCatalog::all() as $code => $capability) {
            if (! $enabled($code)) {
                continue;
            }

            foreach ($capability['dependencies'] ?? [] as $dependency) {
                if (! $enabled($dependency)) {
                    $errors[] = "La capacidad {$capability['name']} requiere activar {$dependency}.";
                }
            }
        }

        if ($enabled('uses_recipes') && ! $enabled('uses_inventory')) {
            $errors[] = 'No se pueden activar recetas sin inventario, porque no habria insumos que descontar.';
        }

        if ($enabled('uses_tables') && (! $enabled('uses_pos') || $businessType !== 'restaurant')) {
            $errors[] = 'Las mesas requieren POS restaurante activo.';
        }

        if (($billing['enabled'] ?? false) || $enabled('uses_billing')) {
            if (($sales['customer_mode'] ?? 'required') === 'hidden') {
                $errors[] = 'Facturacion activa requiere clientes visibles con datos fiscales.';
            }

            if (! ($billing['require_customer_tax_data'] ?? true)) {
                $warnings[] = 'Facturacion activa sin exigir NIT/razon social puede causar rechazos SIAT.';
            }
        }

        if (($cash['required_to_sell'] ?? false) && ! $module('cash')) {
            $errors[] = 'Caja obligatoria para vender requiere que el modulo Caja este activo.';
        }

        if ($module('reservations') && $enabled('uses_reservations') && ! ($reservations['calendar_enabled'] ?? true)) {
            $warnings[] = 'Reservas activas sin calendario limitan el control de disponibilidad.';
        }

        if ($enabled('uses_delivery') && ($configuration['contacts']['delivery_address_mode'] ?? 'optional') === 'hidden') {
            $errors[] = 'Delivery activo requiere direccion de entrega visible u obligatoria.';
        }

        if ($enabled('uses_commissions') && ! $module('workers')) {
            $errors[] = 'Comisiones activas requieren gestion de trabajadores o responsables.';
        }

        if ($enabled('uses_service_orders') && ! $module('service_orders')) {
            $errors[] = 'Ordenes de servicio requieren activar el modulo Ordenes de servicio.';
        }

        if ($enabled('uses_rentals') && ! $enabled('uses_reservable_resources')) {
            $errors[] = 'Alquileres requieren recursos reservables para bloquear disponibilidad.';
        }

        if ($enabled('uses_production') && ! $module('production')) {
            $errors[] = 'Produccion activa requiere el modulo Produccion.';
        }

        $engineRequirements = [
            'uses_dynamic_entities' => 'dynamic_entities_engine',
            'uses_dynamic_fields' => 'dynamic_fields_engine',
            'uses_dynamic_relationships' => 'dynamic_relationships_engine',
            'uses_dynamic_forms' => 'dynamic_forms_engine',
            'uses_document_templates' => 'document_engine_v2',
            'uses_report_templates' => 'report_templates_engine',
            'uses_approval_flows' => 'approval_engine',
            'uses_automation_rules' => 'automation_engine',
            'uses_attachments' => 'attachments_engine',
            'uses_field_level_permissions' => 'field_permissions_engine',
            'uses_integrations' => 'integrations_engine',
        ];

        foreach ($engineRequirements as $capability => $requiredFlag) {
            if ($enabled($capability) && ! $flag($requiredFlag)) {
                $errors[] = "La capacidad {$capability} requiere habilitar el motor tecnico {$requiredFlag}.";
            }
        }

        if (($configuration['fields']['max_fields_per_entity'] ?? 0) > ($configuration['performance']['max_dynamic_fields_per_entity'] ?? 80)) {
            $errors[] = 'El limite de campos por entidad no puede superar el limite de rendimiento configurado.';
        }

        if (($configuration['attachments']['enabled'] ?? false) && ! $flag('attachments_engine')) {
            $errors[] = 'Adjuntos activos requieren habilitar el motor tecnico de adjuntos.';
        }

        if (($configuration['approvals']['enabled'] ?? false) && ! $flag('approval_engine')) {
            $errors[] = 'Aprobaciones activas requieren habilitar el motor tecnico de aprobaciones.';
        }

        if (($configuration['automations']['enabled'] ?? false) && ! $flag('automation_engine')) {
            $errors[] = 'Automatizaciones activas requieren habilitar el motor tecnico de automatizaciones.';
        }

        return [
            'valid' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }
}
