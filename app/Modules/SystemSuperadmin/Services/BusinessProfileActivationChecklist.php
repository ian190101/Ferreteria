<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Modules\Branches\Models\Branch;
use App\Modules\HumanResources\Models\Worker;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Purchases\Models\Supplier;
use App\Modules\Restaurant\Models\Recipe;
use App\Modules\Restaurant\Models\RestaurantTable;
use App\Modules\Sales\Models\Currency;
use App\Modules\SystemSuperadmin\Models\PrinterProfile;
use App\Modules\SystemSuperadmin\Models\ReservableResource;

class BusinessProfileActivationChecklist
{
    public function evaluate(string $businessType, array $configuration): array
    {
        $configuration = BusinessProfileConfiguration::normalized($configuration);
        $compatibility = app(BusinessProfileCompatibilityValidator::class)->validate($businessType, $configuration);
        $requirements = $configuration['activation_checklist']['minimum_requirements'] ?? [];

        $blockers = [];
        $warnings = $compatibility['warnings'];
        $passed = [];

        foreach ($compatibility['errors'] as $error) {
            $blockers[] = $this->item('compatibility', 'Compatibilidad del perfil', $error);
        }

        foreach ($requirements as $requirement) {
            $result = $this->checkRequirement((string) $requirement, $configuration);

            if ($result['status'] === 'passed') {
                $passed[] = $result;
                continue;
            }

            if ($result['status'] === 'warning') {
                $warnings[] = $result['message'];
                continue;
            }

            $blockers[] = $result;
        }

        foreach ($this->structuralChecks($configuration) as $result) {
            if ($result['status'] === 'warning') {
                $warnings[] = $result['message'];
                continue;
            }

            if ($result['status'] === 'blocked') {
                $blockers[] = $result;
                continue;
            }

            $passed[] = $result;
        }

        $warnings = array_values(array_unique($warnings));

        return [
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'ready' => $blockers === [],
            'blockers' => array_values($this->uniqueItems($blockers)),
            'warnings' => $warnings,
            'passed' => array_values($this->uniqueItems($passed)),
            'checked_at' => now()->toIso8601String(),
        ];
    }

    private function checkRequirement(string $requirement, array $configuration): array
    {
        return match ($requirement) {
            'branch' => Branch::query()->where('is_active', true)->exists()
                ? $this->item($requirement, 'Sucursal activa', 'Existe al menos una sucursal activa.', 'passed')
                : $this->item($requirement, 'Sucursal activa', 'Debes crear al menos una sucursal activa antes de aplicar este perfil.'),
            'currency' => Currency::query()->where('is_active', true)->exists()
                ? $this->item($requirement, 'Moneda activa', 'Existe al menos una moneda activa.', 'passed')
                : $this->item($requirement, 'Moneda activa', 'Debes crear al menos una moneda activa para ventas y reportes.'),
            'cash' => (bool) data_get($configuration, 'modules.cash', false)
                ? $this->item($requirement, 'Caja habilitada', 'El modulo Caja esta activo en el perfil.', 'passed')
                : $this->item($requirement, 'Caja habilitada', 'El checklist exige caja, pero el modulo Caja esta desactivado.'),
            'products' => Product::query()->where('is_active', true)->exists()
                ? $this->item($requirement, 'Catalogo inicial', 'Existe al menos un producto o servicio activo.', 'passed')
                : $this->item($requirement, 'Catalogo inicial', 'Debes cargar al menos un producto o servicio activo antes de aplicar este perfil.'),
            'units' => ProductUnit::query()->where('is_active', true)->exists() || Product::query()->whereNotNull('base_unit')->exists()
                ? $this->item($requirement, 'Unidades de medida', 'Hay unidades de medida disponibles.', 'passed')
                : $this->item($requirement, 'Unidades de medida', 'Debes configurar unidades de medida o productos con unidad base.'),
            'suppliers' => Supplier::query()->where('is_active', true)->exists()
                ? $this->item($requirement, 'Proveedores', 'Existe al menos un proveedor activo.', 'passed')
                : $this->item($requirement, 'Proveedores', 'Debes cargar al menos un proveedor activo.'),
            'technicians' => Worker::query()->where('is_active', true)->exists()
                ? $this->item($requirement, 'Tecnicos o trabajadores', 'Existe al menos un trabajador activo.', 'passed')
                : $this->item($requirement, 'Tecnicos o trabajadores', 'Debes crear trabajadores/tecnicos si el flujo los exige.'),
            'tables' => RestaurantTable::query()->where('is_active', true)->exists()
                ? $this->item($requirement, 'Mesas', 'Existe al menos una mesa activa.', 'passed')
                : $this->item($requirement, 'Mesas', 'Debes crear mesas si el perfil trabaja con consumo en mesa.'),
            'recipes' => Recipe::query()->where('is_active', true)->exists()
                ? $this->item($requirement, 'Recetas', 'Existe al menos una receta activa.', 'passed')
                : $this->item($requirement, 'Recetas', 'Debes crear recetas si el perfil descuenta insumos.'),
            'printer_profiles' => PrinterProfile::query()->where('is_active', true)->exists()
                ? $this->item($requirement, 'Impresoras', 'Existe al menos un perfil de impresora activo.', 'passed')
                : $this->item($requirement, 'Impresoras', 'Falta configurar perfiles de impresora para documentos/areas.'),
            'resources' => ReservableResource::query()->where('is_active', true)->exists()
                ? $this->item($requirement, 'Recursos reservables', 'Existe al menos un recurso reservable activo.', 'passed')
                : $this->item($requirement, 'Recursos reservables', 'Debes crear recursos reservables si el perfil usa reservas o alquileres.'),
            default => $this->item($requirement, $requirement, 'Este requisito debe revisarse manualmente porque no tiene una validacion automatica.', 'warning'),
        };
    }

    private function structuralChecks(array $configuration): array
    {
        $checks = [];
        $documents = data_get($configuration, 'documents.active', []);

        if ($documents === []) {
            $checks[] = $this->item('documents', 'Documentos activos', 'El perfil debe tener al menos un documento activo.');
        } else {
            $checks[] = $this->item('documents', 'Documentos activos', 'El perfil tiene documentos activos.', 'passed');
        }

        if (data_get($configuration, 'modules.billing', false) && ! in_array('siat_invoice', $documents, true)) {
            $checks[] = $this->item('billing_document', 'Factura SIAT visible', 'Facturacion activa requiere documento Factura SIAT activo.');
        }

        if (data_get($configuration, 'approvals.enabled', false) && ! data_get($configuration, 'feature_flags.approval_engine', false)) {
            $checks[] = $this->item('approval_engine', 'Motor de aprobaciones', 'Aprobaciones activas requieren su feature flag tecnico.');
        }

        if (data_get($configuration, 'attachments.enabled', false) && (int) data_get($configuration, 'attachments.max_file_mb', 0) <= 0) {
            $checks[] = $this->item('attachments_size', 'Tamano de adjuntos', 'Adjuntos activos requieren un tamano maximo valido.');
        }

        if (data_get($configuration, 'migration.allow_destructive_migrations', false)) {
            $checks[] = $this->item('destructive_migrations', 'Migraciones destructivas', 'Las migraciones destructivas no deben habilitarse para produccion real.', 'warning');
        }

        return $checks;
    }

    private function item(string $code, string $label, string $message, string $status = 'blocked'): array
    {
        return compact('code', 'label', 'message', 'status');
    }

    private function uniqueItems(array $items): array
    {
        return collect($items)
            ->unique(fn (array $item) => $item['code'].'|'.$item['message'])
            ->values()
            ->all();
    }
}
