<?php

namespace App\Modules\SystemSuperadmin\Services;

class BusinessPresetReadinessService
{
    private const ENGINE_REQUIREMENTS = [
        'uses_dynamic_entities' => 'dynamic_entities_engine',
        'uses_dynamic_fields' => 'dynamic_fields_engine',
        'uses_dynamic_relationships' => 'dynamic_relationships_engine',
        'uses_dynamic_forms' => 'dynamic_forms_engine',
        'uses_dynamic_pos' => 'commercial_flow_engine',
        'uses_commercial_flow_rules' => 'commercial_flow_engine',
        'uses_document_templates' => 'document_engine_v2',
        'uses_report_templates' => 'report_templates_engine',
        'uses_approval_flows' => 'approval_engine',
        'uses_automation_rules' => 'automation_engine',
        'uses_attachments' => 'attachments_engine',
        'uses_formula_calculations' => 'formula_engine',
        'uses_construction_calculations' => 'formula_engine',
        'uses_field_level_permissions' => 'field_permissions_engine',
        'uses_data_governance' => 'data_governance_engine',
        'uses_operational_traceability' => 'operational_traceability_engine',
        'uses_integrations' => 'integrations_engine',
        'uses_advanced_multibranch' => 'advanced_multibranch_engine',
        'uses_ai_assistant' => 'ai_assistant_engine',
        'uses_ai_internal_chat' => 'ai_assistant_engine',
        'uses_ai_customer_bot' => 'ai_assistant_engine',
        'uses_ai_owner_analytics' => 'ai_assistant_engine',
        'uses_ai_voice' => 'ai_assistant_engine',
        'uses_ai_exports' => 'ai_assistant_engine',
        'uses_ai_order_capture' => 'ai_assistant_engine',
        'uses_ai_external_api' => 'ai_assistant_engine',
    ];

    public function __construct(
        private readonly BusinessProfilePresetFactory $presets,
        private readonly BusinessProfileCompatibilityValidator $compatibility,
        private readonly BusinessSpecializedBlueprintFactory $blueprints,
        private readonly BusinessSpecializedReportTemplateFactory $reportTemplates,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return collect($this->presets->officialPresets())
            ->map(fn (array $preset): array => $this->evaluate($preset))
            ->values()
            ->all();
    }

    /**
     * @param array{name:string,business_type:string,description:string,configuration:array<string,mixed>} $preset
     * @return array<string, mixed>
     */
    public function evaluate(array $preset): array
    {
        $configuration = BusinessProfileConfiguration::normalized($preset['configuration']);
        $compatibility = $this->compatibility->validate($preset['business_type'], $configuration);

        $criteria = [
            $this->criterion(
                'schema_version',
                'Version de esquema 2.0',
                ($configuration['schema_version'] ?? null) === 2,
                'El preset usa la configuracion normalizada 2.0.',
                'El preset no esta normalizado en esquema 2.0.',
            ),
            $this->criterion(
                'identity_matches_business_type',
                'Identidad coherente',
                data_get($configuration, 'identity.business_type') === $preset['business_type'],
                'La identidad del perfil coincide con el tipo de negocio.',
                'La identidad del perfil no coincide con el tipo de negocio del preset.',
            ),
            $this->criterion(
                'compatibility',
                'Compatibilidad backend',
                $compatibility['errors'] === [],
                'El validador de compatibilidad no encontro bloqueos.',
                'El preset tiene errores de compatibilidad: '.implode(' | ', $compatibility['errors']),
            ),
            $this->criterion(
                'no_destructive_migrations',
                'Migracion no destructiva',
                ! (bool) data_get($configuration, 'migration.allow_destructive_migrations', false),
                'El preset no permite migraciones destructivas.',
                'El preset intenta habilitar migraciones destructivas.',
            ),
            $this->criterion(
                'regression_required',
                'Regresion obligatoria',
                (bool) data_get($configuration, 'migration.requires_regression_tests', true),
                'El preset exige pruebas de regresion antes de aplicar.',
                'El preset no exige pruebas de regresion antes de aplicar.',
                'warning',
            ),
            ...$this->engineCriteria($configuration),
            ...$this->specializedCriteria($preset['name'], $configuration),
        ];

        $blockers = collect($criteria)->where('status', 'blocked')->values()->all();
        $warnings = collect($criteria)->where('status', 'warning')->values()->all();

        return [
            'name' => $preset['name'],
            'business_type' => $preset['business_type'],
            'status' => $blockers === [] ? ($warnings === [] ? 'ready' : 'warning') : 'blocked',
            'ready' => $blockers === [],
            'criteria' => $criteria,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'compatibility_warnings' => $compatibility['warnings'],
            'specialized_blueprint' => $this->blueprints->forPreset($preset['name']) !== null,
            'specialized_reports' => count($this->reportTemplates->forPreset($preset['name'])),
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function engineCriteria(array $configuration): array
    {
        $criteria = [];

        foreach (self::ENGINE_REQUIREMENTS as $capability => $flag) {
            if (! (bool) data_get($configuration, 'capabilities.'.$capability, false)) {
                continue;
            }

            $criteria[] = $this->criterion(
                'engine_'.$capability,
                "Motor tecnico para {$capability}",
                (bool) data_get($configuration, 'feature_flags.'.$flag, false),
                "La capacidad {$capability} tiene habilitado {$flag}.",
                "La capacidad {$capability} requiere habilitar {$flag}.",
            );
        }

        return $criteria;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function specializedCriteria(string $presetName, array $configuration): array
    {
        $criteria = [];
        $requiresBlueprint = $this->requiresBlueprint($configuration);
        $hasBlueprint = $this->blueprints->forPreset($presetName) !== null;

        if ($requiresBlueprint || $hasBlueprint) {
            $criteria[] = $this->criterion(
                'specialized_blueprint',
                'Blueprint especializado',
                $hasBlueprint,
                'El preset tiene blueprint especializado para entidades, campos, relaciones, formularios o formulas.',
                'El preset activa logica dinamica especializada pero no tiene blueprint asociado.',
            );
        }

        $requiresReports = (bool) data_get($configuration, 'capabilities.uses_report_templates', false);
        $reportsCount = count($this->reportTemplates->forPreset($presetName));

        if ($requiresReports || $reportsCount > 0) {
            $criteria[] = $this->criterion(
                'specialized_reports',
                'Reportes especializados',
                $reportsCount > 0,
                'El preset tiene plantillas de reportes por rubro.',
                'El preset activa reportes configurables pero no tiene plantillas por rubro.',
            );
        }

        return $criteria;
    }

    private function requiresBlueprint(array $configuration): bool
    {
        foreach ([
            'uses_health_records',
            'uses_dental_chart',
            'uses_loans',
            'uses_guarantees',
            'uses_projects',
            'uses_construction_calculations',
            'uses_vehicles',
            'uses_equipment_repairs',
            'uses_pet_records',
            'uses_memberships',
            'uses_courses',
        ] as $capability) {
            if ((bool) data_get($configuration, 'capabilities.'.$capability, false)) {
                return true;
            }
        }

        return false;
    }

    private function criterion(
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
