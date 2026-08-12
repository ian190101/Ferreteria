<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Modules\SystemSuperadmin\Models\ApprovalFlowRule;
use App\Modules\SystemSuperadmin\Models\AttachmentDefinition;
use App\Modules\SystemSuperadmin\Models\AutomationRule;
use App\Modules\SystemSuperadmin\Models\BusinessCommercialFlowRule;
use App\Modules\SystemSuperadmin\Models\BusinessIntegrationConnector;
use App\Modules\SystemSuperadmin\Models\BranchOperationPolicy;
use App\Modules\SystemSuperadmin\Models\BusinessStateDefinition;
use App\Modules\SystemSuperadmin\Models\CalculationFormula;
use App\Modules\SystemSuperadmin\Models\CustomFieldDefinition;
use App\Modules\SystemSuperadmin\Models\CustomerQrChannel;
use App\Modules\SystemSuperadmin\Models\DynamicDocumentTemplate;
use App\Modules\SystemSuperadmin\Models\DynamicEntity;
use App\Modules\SystemSuperadmin\Models\DynamicFormDefinition;
use App\Modules\SystemSuperadmin\Models\DynamicRelationshipDefinition;
use App\Modules\SystemSuperadmin\Models\DynamicReportTemplate;
use App\Modules\SystemSuperadmin\Models\OperationalTrace;
use App\Modules\SystemSuperadmin\Models\ReservableResource;
use App\Modules\SystemSuperadmin\Models\WorkflowDefinition;
use App\Modules\SystemSuperadmin\Models\WorkflowTransition;
use App\Modules\AiAssistant\Models\AiAssistantChannel;

class BusinessTransversalReadinessService
{
    private const ENGINES = [
        'dynamic_entities' => [
            'label' => 'Entidades configurables',
            'capability' => 'uses_dynamic_entities',
            'flag' => 'dynamic_entities_engine',
            'section' => 'entities',
            'model' => DynamicEntity::class,
        ],
        'dynamic_fields' => [
            'label' => 'Campos personalizados',
            'capability' => 'uses_dynamic_fields',
            'flag' => 'dynamic_fields_engine',
            'section' => 'custom-fields',
            'model' => CustomFieldDefinition::class,
        ],
        'dynamic_relationships' => [
            'label' => 'Relaciones entre entidades',
            'capability' => 'uses_dynamic_relationships',
            'flag' => 'dynamic_relationships_engine',
            'section' => 'relationships',
            'model' => DynamicRelationshipDefinition::class,
        ],
        'dynamic_forms' => [
            'label' => 'Formularios por flujo',
            'capability' => 'uses_dynamic_forms',
            'flag' => 'dynamic_forms_engine',
            'section' => 'forms',
            'model' => DynamicFormDefinition::class,
        ],
        'field_permissions' => [
            'label' => 'Permisos por campo',
            'capability' => 'uses_field_level_permissions',
            'flag' => 'field_permissions_engine',
            'section' => 'field-permissions',
            'configuration_path' => 'field_permissions.rules',
        ],
        'dynamic_workflows' => [
            'label' => 'Estados y flujos',
            'capability' => 'uses_custom_statuses',
            'flag' => 'dynamic_workflows_engine',
            'section' => 'workflows',
            'models' => [WorkflowDefinition::class, BusinessStateDefinition::class, WorkflowTransition::class],
        ],
        'reservable_resources' => [
            'label' => 'Recursos reservables',
            'capability' => 'uses_reservable_resources',
            'flag' => 'reservable_resources_engine',
            'section' => 'resources',
            'model' => ReservableResource::class,
        ],
        'formula_calculations' => [
            'label' => 'Formulas de calculo',
            'capability' => 'uses_formula_calculations',
            'flag' => 'formula_engine',
            'section' => 'calculation-formulas',
            'model' => CalculationFormula::class,
        ],
        'commercial_flows' => [
            'label' => 'Flujos comerciales/POS',
            'capability' => 'uses_commercial_flow_rules',
            'flag' => 'commercial_flow_engine',
            'section' => 'commercial-flows',
            'model' => BusinessCommercialFlowRule::class,
        ],
        'document_templates' => [
            'label' => 'Documentos 2.0',
            'capability' => 'uses_document_templates',
            'flag' => 'document_engine_v2',
            'section' => 'document-templates',
            'model' => DynamicDocumentTemplate::class,
        ],
        'report_templates' => [
            'label' => 'Reportes configurables',
            'capability' => 'uses_report_templates',
            'flag' => 'report_templates_engine',
            'section' => 'report-templates',
            'model' => DynamicReportTemplate::class,
        ],
        'attachments' => [
            'label' => 'Adjuntos y evidencias',
            'capability' => 'uses_attachments',
            'flag' => 'attachments_engine',
            'section' => 'attachments',
            'model' => AttachmentDefinition::class,
        ],
        'data_governance' => [
            'label' => 'Gobierno de datos',
            'capability' => 'uses_data_governance',
            'flag' => 'data_governance_engine',
            'section' => 'governance',
            'configuration_path' => 'governance',
            'definitions_required' => false,
        ],
        'operational_traceability' => [
            'label' => 'Trazabilidad operativa',
            'capability' => 'uses_operational_traceability',
            'flag' => 'operational_traceability_engine',
            'section' => 'traceability',
            'model' => OperationalTrace::class,
            'definitions_required' => false,
        ],
        'approvals' => [
            'label' => 'Aprobaciones',
            'capability' => 'uses_approval_flows',
            'flag' => 'approval_engine',
            'section' => 'approval-flows',
            'model' => ApprovalFlowRule::class,
        ],
        'automations' => [
            'label' => 'Automatizaciones',
            'capability' => 'uses_automation_rules',
            'flag' => 'automation_engine',
            'section' => 'automation-rules',
            'model' => AutomationRule::class,
        ],
        'integrations' => [
            'label' => 'Integraciones',
            'capability' => 'uses_integrations',
            'flag' => 'integrations_engine',
            'section' => 'integrations',
            'model' => BusinessIntegrationConnector::class,
        ],
        'advanced_multibranch' => [
            'label' => 'Multi-sucursal avanzada',
            'capability' => 'uses_advanced_multibranch',
            'flag' => 'advanced_multibranch_engine',
            'section' => 'branch-policies',
            'model' => BranchOperationPolicy::class,
        ],
        'customer_qr_ordering' => [
            'label' => 'Pedidos por QR',
            'capability' => 'uses_customer_qr_ordering',
            'flag' => 'customer_qr_ordering_engine',
            'section' => 'customer-qr-channels',
            'model' => CustomerQrChannel::class,
        ],
        'ai_assistant' => [
            'label' => 'Chatbot IA',
            'capability' => 'uses_ai_assistant',
            'flag' => 'ai_assistant_engine',
            'section' => 'ai-assistant',
            'model' => AiAssistantChannel::class,
            'definitions_required' => false,
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public function evaluate(): array
    {
        $profile = ActiveBusinessProfile::payload();
        $engines = collect(self::ENGINES)
            ->map(fn (array $engine, string $code): array => $this->evaluateEngine($code, $engine, $profile))
            ->values();

        return [
            'overall' => [
                'total' => $engines->count(),
                'ready' => $engines->where('status', 'ready')->count(),
                'prepared' => $engines->where('status', 'prepared')->count(),
                'inactive' => $engines->where('status', 'inactive')->count(),
                'warnings' => $engines->where('status', 'warning')->count(),
                'blocked' => $engines->where('status', 'blocked')->count(),
                'can_apply_profile_safely' => $engines->where('status', 'blocked')->isEmpty(),
            ],
            'engines' => $engines->all(),
        ];
    }

    /**
     * @param array<string, mixed> $engine
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function evaluateEngine(string $code, array $engine, array $profile): array
    {
        $capabilityEnabled = (bool) data_get($profile, 'capabilities.'.$engine['capability'], false);
        $flagEnabled = (bool) data_get($profile, 'feature_flags.'.$engine['flag'], false);
        $definitions = $this->definitionCount($engine);
        $definitionsForStatus = ($engine['definitions_required'] ?? true) || $capabilityEnabled || $flagEnabled
            ? $definitions
            : 0;

        [$status, $message] = match (true) {
            $capabilityEnabled && ! $flagEnabled => ['blocked', 'La capacidad esta activa, pero el feature flag tecnico esta apagado.'],
            ! $capabilityEnabled && $flagEnabled => ['warning', 'El feature flag tecnico esta activo sin capacidad asociada en el perfil.'],
            $capabilityEnabled && $flagEnabled && $definitionsForStatus === 0 && ($engine['definitions_required'] ?? true) => ['warning', 'El motor esta activo pero aun no tiene definiciones configuradas.'],
            $capabilityEnabled && $flagEnabled => ['ready', 'El motor esta activo y tiene configuracion disponible.'],
            $definitionsForStatus > 0 => ['prepared', 'Hay definiciones preparadas, pero el perfil activo todavia no usa este motor.'],
            default => ['inactive', 'Motor sin activar y sin datos preparados.'],
        };

        return [
            'code' => $code,
            'label' => $engine['label'],
            'section' => $engine['section'],
            'capability' => $engine['capability'],
            'feature_flag' => $engine['flag'],
            'capability_enabled' => $capabilityEnabled,
            'feature_flag_enabled' => $flagEnabled,
            'definitions_count' => $definitions,
            'status' => $status,
            'message' => $message,
        ];
    }

    /**
     * @param array<string, mixed> $engine
     */
    private function definitionCount(array $engine): int
    {
        if (isset($engine['model'])) {
            /** @var class-string<\Illuminate\Database\Eloquent\Model> $model */
            $model = $engine['model'];

            return $model::query()->count();
        }

        if (isset($engine['configuration_path'])) {
            $value = data_get(ActiveBusinessProfile::payload(), $engine['configuration_path']);

            if (is_array($value)) {
                return count($value);
            }

            return filled($value) ? 1 : 0;
        }

        return collect($engine['models'] ?? [])
            ->sum(fn (string $model): int => $model::query()->count());
    }
}
