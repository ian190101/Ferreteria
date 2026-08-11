<?php

namespace App\Modules\SystemSuperadmin\Services;

class BusinessProfileMigrationAuditService
{
    private const PRESERVED_LEGACY_PATHS = [
        'modules.quotes',
        'modules.sales_notes',
        'modules.purchases',
        'modules.cash',
        'modules.inventory',
        'modules.customers',
        'modules.suppliers',
        'sales.workflow',
        'sales.quotation_mode',
        'sales.customer_mode',
        'cash.required_to_sell',
        'inventory.stock_discount_timing',
        'billing.invoice_flow',
    ];

    public function __construct(
        private readonly BusinessProfileMigrationMapper $mapper,
        private readonly BusinessProfileDiffService $diffService,
        private readonly BusinessProfileCompatibilityValidator $compatibilityValidator,
    ) {}

    public function previewToVersionTwo(string $businessType, array $legacyConfiguration): array
    {
        $before = BusinessProfileConfiguration::normalized($legacyConfiguration);
        $after = $this->mapper->toVersionTwo($businessType, $legacyConfiguration);
        $compatibility = $this->compatibilityValidator->validate($businessType, $after);
        $antiRupture = $this->antiRuptureChecks($before, $after);

        $blockers = array_values(array_unique([
            ...$compatibility['errors'],
            ...array_column(
                array_filter($antiRupture, fn (array $check): bool => $check['status'] === 'blocked'),
                'message',
            ),
        ]));

        $warnings = array_values(array_unique([
            ...$compatibility['warnings'],
            ...array_column(
                array_filter($antiRupture, fn (array $check): bool => $check['status'] === 'warning'),
                'message',
            ),
        ]));

        return [
            'safe_to_apply' => $blockers === [],
            'source_schema_version' => (int) ($before['schema_version'] ?? 1),
            'target_schema_version' => (int) ($after['schema_version'] ?? 2),
            'business_type' => $businessType,
            'configuration' => $after,
            'changes' => $this->diffService->compare($before, $after),
            'mapping' => $this->mappingSummary($before, $after),
            'anti_rupture' => $antiRupture,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'requires_regression_tests' => (bool) data_get($after, 'migration.requires_regression_tests', true),
        ];
    }

    public function mappingSummary(array $before, array $after): array
    {
        return [
            'schema_version' => [
                'from' => (int) ($before['schema_version'] ?? 1),
                'to' => (int) ($after['schema_version'] ?? 2),
            ],
            'identity.business_type' => [
                'from' => data_get($before, 'identity.business_type', 'hardware_store'),
                'to' => data_get($after, 'identity.business_type'),
            ],
            'modules_to_capabilities' => [
                'modules.inventory' => 'capabilities.uses_inventory',
                'modules.pos' => 'capabilities.uses_pos',
                'modules.cash' => 'capabilities.uses_cash',
                'modules.cash_flow' => 'capabilities.uses_cash_flow',
                'modules.banks' => 'capabilities.uses_banks',
                'modules.billing' => 'capabilities.uses_billing',
                'modules.deliveries' => 'capabilities.uses_delivery',
                'modules.services' => 'capabilities.uses_services',
                'modules.service_orders' => 'capabilities.uses_service_orders',
                'modules.production' => 'capabilities.uses_production',
            ],
            'legacy_safety' => [
                'preserve_legacy_behavior' => (bool) data_get($after, 'migration.preserve_legacy_behavior', true),
                'allow_destructive_migrations' => (bool) data_get($after, 'migration.allow_destructive_migrations', false),
                'requires_regression_tests' => (bool) data_get($after, 'migration.requires_regression_tests', true),
            ],
        ];
    }

    private function antiRuptureChecks(array $before, array $after): array
    {
        $checks = [
            $this->check(
                'no_destructive_migrations',
                'Migraciones destructivas desactivadas',
                ! (bool) data_get($after, 'migration.allow_destructive_migrations', false),
                'La migracion 2.0 no permite cambios destructivos por defecto.',
                'El perfil intenta habilitar migraciones destructivas; debe corregirse antes de aplicar.',
            ),
            $this->check(
                'preserve_legacy_behavior',
                'Comportamiento legado preservado',
                (bool) data_get($after, 'migration.preserve_legacy_behavior', true),
                'El perfil conserva el comportamiento anterior mientras se activan motores nuevos por configuracion.',
                'El perfil no conserva comportamiento legado; puede cambiar ferreteria actual.',
            ),
            $this->check(
                'requires_regression_tests',
                'Regresion obligatoria',
                (bool) data_get($after, 'migration.requires_regression_tests', true),
                'La migracion exige pruebas de ventas, cotizaciones, notas, inventario, caja y permisos.',
                'La migracion no exige pruebas de regresion antes de aplicar.',
                'warning',
            ),
            $this->check(
                'feature_flags_disabled_by_default',
                'Motores nuevos bajo feature flags',
                $this->newEnginesRemainDisabled($after),
                'Los motores dinamicos siguen apagados salvo activacion explicita del perfil.',
                'Hay motores dinamicos activos por defecto; puede aparecer funcionalidad no autorizada.',
            ),
        ];

        foreach (self::PRESERVED_LEGACY_PATHS as $path) {
            if (data_get($before, $path) === data_get($after, $path)) {
                $checks[] = [
                    'code' => 'preserve_'.$path,
                    'label' => 'Preservar '.$path,
                    'status' => 'passed',
                    'message' => "El valor {$path} se mantiene sin cambios.",
                ];

                continue;
            }

            $checks[] = [
                'code' => 'preserve_'.$path,
                'label' => 'Preservar '.$path,
                'status' => 'warning',
                'message' => "El valor {$path} cambia en el preview y debe revisarse antes de aplicar.",
                'before' => data_get($before, $path),
                'after' => data_get($after, $path),
            ];
        }

        return $checks;
    }

    private function newEnginesRemainDisabled(array $configuration): bool
    {
        $flags = $configuration['feature_flags'] ?? [];

        foreach ($flags as $key => $enabled) {
            if ($key === 'business_profile_v2_core') {
                continue;
            }

            if ((bool) $enabled) {
                return false;
            }
        }

        return true;
    }

    private function check(
        string $code,
        string $label,
        bool $passes,
        string $passedMessage,
        string $failedMessage,
        string $failedStatus = 'blocked',
    ): array {
        return [
            'code' => $code,
            'label' => $label,
            'status' => $passes ? 'passed' : $failedStatus,
            'message' => $passes ? $passedMessage : $failedMessage,
        ];
    }
}
