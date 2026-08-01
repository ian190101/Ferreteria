<?php

namespace App\Modules\SystemSuperadmin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HumanResources\Models\Worker;
use App\Modules\SystemSuperadmin\Models\BusinessCurrency;
use App\Modules\SystemSuperadmin\Models\BusinessModuleLicense;
use App\Modules\SystemSuperadmin\Models\BusinessStateDefinition;
use App\Modules\SystemSuperadmin\Models\CommissionRule;
use App\Modules\SystemSuperadmin\Models\CustomFieldDefinition;
use App\Modules\SystemSuperadmin\Models\DynamicEntity;
use App\Modules\SystemSuperadmin\Models\ImportProfileTemplate;
use App\Modules\SystemSuperadmin\Models\NotificationRule;
use App\Modules\SystemSuperadmin\Models\PriceList;
use App\Modules\SystemSuperadmin\Models\PrinterProfile;
use App\Modules\SystemSuperadmin\Models\ReservableResource;
use App\Modules\SystemSuperadmin\Models\WorkflowDefinition;
use App\Modules\SystemSuperadmin\Models\WorkflowTransition;
use App\Modules\SystemSuperadmin\Services\BusinessTransversalConfiguration;
use App\Modules\SystemSuperadmin\Services\BusinessTransversalDataService;
use App\Modules\SystemSuperadmin\Services\DynamicEntityRegistry;
use App\Support\SystemCacheInvalidator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BusinessTransversalController extends Controller
{
    public function index(BusinessTransversalDataService $service): Response
    {
        return Inertia::render('SystemSuperadmin/BusinessTransversal/Index', $service->payload());
    }

    public function store(Request $request, string $section): RedirectResponse
    {
        $modelClass = $this->modelClass($section);
        $payload = $this->validatedPayload($request, $section);

        DB::transaction(function () use ($modelClass, $payload, $section): void {
            $this->clearOtherExclusiveDefaults($section, $payload);

            /** @var class-string<Model> $modelClass */
            $modelClass::query()->create($payload);
        });

        SystemCacheInvalidator::bumpOperational();

        return back()->with('success', $this->sectionLabel($section).' guardado correctamente.');
    }

    public function update(Request $request, string $section, int $record): RedirectResponse
    {
        $model = $this->findModel($section, $record);
        $payload = $this->validatedPayload($request, $section, $model);

        DB::transaction(function () use ($model, $payload, $section): void {
            $this->clearOtherExclusiveDefaults($section, $payload, $model);

            $model->update($payload);
        });

        SystemCacheInvalidator::bumpOperational();

        return back()->with('success', $this->sectionLabel($section).' actualizado correctamente.');
    }

    public function destroy(string $section, int $record): RedirectResponse
    {
        $model = $this->findModel($section, $record);

        if (array_key_exists('is_active', $model->getAttributes())) {
            $model->update(['is_active' => false]);
        } elseif (array_key_exists('is_enabled', $model->getAttributes())) {
            $model->update(['is_enabled' => false]);
        } else {
            $model->delete();
        }

        SystemCacheInvalidator::bumpOperational();

        return back()->with('success', $this->sectionLabel($section).' desactivado correctamente.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request, string $section, ?Model $current = null): array
    {
        $options = BusinessTransversalConfiguration::options();

        return match ($section) {
            'entities' => $this->validateEntity($request, $options, $current),
            'custom-fields' => $this->validateCustomField($request, $options, $current),
            'workflows' => $this->validateWorkflow($request, $options, $current),
            'states' => $this->validateState($request, $options),
            'workflow-transitions' => $this->validateWorkflowTransition($request),
            'resources' => $this->validateResource($request, $options),
            'price-lists' => $this->validatePriceList($request, $options),
            'commissions' => $this->validateCommission($request, $options),
            'notifications' => $this->validateNotification($request, $options),
            'currencies' => $this->validateCurrency($request),
            'printers' => $this->validatePrinter($request, $options),
            'licenses' => $this->validateLicense($request, $options),
            'imports' => $this->validateImportTemplate($request, $options),
            default => abort(404),
        };
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function validateWorkflow(Request $request, array $options, ?Model $current = null): array
    {
        $entityOptions = array_keys(app(DynamicEntityRegistry::class)->options());
        $uniqueCode = Rule::unique('workflow_definitions', 'code')
            ->where(fn ($query) => $query->where('entity_type', (string) $request->input('entity_type')));

        if ($current instanceof WorkflowDefinition) {
            $uniqueCode->ignore($current->id);
        }

        $data = $request->validate([
            'entity_type' => ['required', 'string', Rule::in($entityOptions)],
            'code' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/', $uniqueCode],
            'name' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:1000'],
            'initial_state_code' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/'],
            'final_state_codes_csv' => ['nullable', 'string', 'max:1000'],
            'settings_text' => ['nullable', 'string', 'max:3000'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        return [
            'entity_type' => $data['entity_type'],
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'initial_state_code' => $data['initial_state_code'] ?? null,
            'final_state_codes' => BusinessTransversalConfiguration::listFromCsv($data['final_state_codes_csv'] ?? null),
            'settings' => BusinessTransversalConfiguration::jsonFromText($data['settings_text'] ?? null),
            'is_default' => (bool) ($data['is_default'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function validateEntity(Request $request, array $options, ?Model $current = null): array
    {
        $baseEntities = array_keys(BusinessTransversalConfiguration::baseEntities());
        $uniqueCode = Rule::unique('dynamic_entities', 'code');

        if ($current instanceof DynamicEntity) {
            $uniqueCode->ignore($current->id);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9_]+$/', Rule::notIn($baseEntities), $uniqueCode],
            'label' => ['required', 'string', 'max:160'],
            'plural_label' => ['nullable', 'string', 'max:180'],
            'base_entity' => ['nullable', 'string', Rule::in($baseEntities)],
            'module' => ['nullable', 'string', Rule::in(array_keys($options['modules']))],
            'icon' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
            'mode' => ['required', 'string', Rule::in(array_keys($options['entityModes']))],
            'is_visible' => ['boolean'],
            'is_editable' => ['boolean'],
            'is_required' => ['boolean'],
            'is_exportable' => ['boolean'],
            'is_reportable' => ['boolean'],
            'is_auditable' => ['boolean'],
            'is_sensitive' => ['boolean'],
            'retention_policy' => ['required', 'string', Rule::in(array_keys($options['retentionPolicies']))],
            'permissions_text' => ['nullable', 'string', 'max:3000'],
            'settings_text' => ['nullable', 'string', 'max:3000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['boolean'],
        ], [], [
            'code' => 'codigo interno de entidad',
            'label' => 'nombre visible',
            'base_entity' => 'entidad base',
            'retention_policy' => 'politica de retencion',
        ]);

        return [
            'code' => $data['code'],
            'label' => $data['label'],
            'plural_label' => $data['plural_label'] ?? null,
            'base_entity' => $data['base_entity'] ?? null,
            'module' => $data['module'] ?? null,
            'icon' => $data['icon'] ?? null,
            'mode' => $data['mode'],
            'is_visible' => (bool) ($data['is_visible'] ?? true),
            'is_editable' => (bool) ($data['is_editable'] ?? true),
            'is_required' => (bool) ($data['is_required'] ?? false),
            'is_exportable' => (bool) ($data['is_exportable'] ?? false),
            'is_reportable' => (bool) ($data['is_reportable'] ?? false),
            'is_auditable' => (bool) ($data['is_auditable'] ?? true),
            'is_sensitive' => (bool) ($data['is_sensitive'] ?? false),
            'retention_policy' => $data['retention_policy'],
            'permissions' => BusinessTransversalConfiguration::jsonFromText($data['permissions_text'] ?? null),
            'settings' => BusinessTransversalConfiguration::jsonFromText($data['settings_text'] ?? null),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function validateCustomField(Request $request, array $options, ?Model $current = null): array
    {
        $entityOptions = array_keys(app(DynamicEntityRegistry::class)->options());
        $uniqueCode = Rule::unique('custom_field_definitions', 'code')
            ->where(fn ($query) => $query->where('entity_type', (string) $request->input('entity_type')));

        if ($current instanceof CustomFieldDefinition) {
            $uniqueCode->ignore($current->id);
        }

        $data = $request->validate([
            'entity_type' => ['required', 'string', Rule::in($entityOptions)],
            'code' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/', $uniqueCode],
            'label' => ['required', 'string', 'max:160'],
            'help_text' => ['nullable', 'string', 'max:500'],
            'placeholder' => ['nullable', 'string', 'max:180'],
            'type' => ['required', 'string', Rule::in(array_keys($options['fieldTypes']))],
            'group' => ['nullable', 'string', 'max:120'],
            'options_csv' => ['nullable', 'string', 'max:1000'],
            'validation_rules_text' => ['nullable', 'string', 'max:2000'],
            'default_value_text' => ['nullable', 'string', 'max:2000'],
            'format' => ['nullable', 'string', 'max:120'],
            'min_value' => ['nullable', 'numeric', 'max:999999999999'],
            'max_value' => ['nullable', 'numeric', 'gte:min_value', 'max:999999999999'],
            'relation_entity_type' => ['nullable', 'string', Rule::in($entityOptions)],
            'metadata_text' => ['nullable', 'string', 'max:3000'],
            'is_required' => ['boolean'],
            'visible_in_forms' => ['boolean'],
            'visible_in_table' => ['boolean'],
            'visible_in_documents' => ['boolean'],
            'visible_in_reports' => ['boolean'],
            'is_exportable' => ['boolean'],
            'is_auditable' => ['boolean'],
            'is_sensitive' => ['boolean'],
            'is_encrypted' => ['boolean'],
            'is_read_only' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['boolean'],
        ], [], [
            'entity_type' => 'entidad',
            'code' => 'codigo interno',
            'label' => 'nombre visible',
            'type' => 'tipo de campo',
        ]);

        return [
            'entity_type' => $data['entity_type'],
            'code' => $data['code'],
            'label' => $data['label'],
            'help_text' => $data['help_text'] ?? null,
            'placeholder' => $data['placeholder'] ?? null,
            'type' => $data['type'],
            'group' => $data['group'] ?? null,
            'options' => BusinessTransversalConfiguration::listFromCsv($data['options_csv'] ?? null),
            'validation_rules' => BusinessTransversalConfiguration::jsonFromText($data['validation_rules_text'] ?? null),
            'default_value' => BusinessTransversalConfiguration::jsonFromText($data['default_value_text'] ?? null),
            'format' => $data['format'] ?? null,
            'min_value' => $data['min_value'] ?? null,
            'max_value' => $data['max_value'] ?? null,
            'relation_entity_type' => $data['relation_entity_type'] ?? null,
            'metadata' => BusinessTransversalConfiguration::jsonFromText($data['metadata_text'] ?? null),
            'is_required' => (bool) ($data['is_required'] ?? false),
            'visible_in_forms' => (bool) ($data['visible_in_forms'] ?? true),
            'visible_in_table' => (bool) ($data['visible_in_table'] ?? false),
            'visible_in_documents' => (bool) ($data['visible_in_documents'] ?? false),
            'visible_in_reports' => (bool) ($data['visible_in_reports'] ?? false),
            'is_exportable' => (bool) ($data['is_exportable'] ?? false),
            'is_auditable' => (bool) ($data['is_auditable'] ?? true),
            'is_sensitive' => (bool) ($data['is_sensitive'] ?? false),
            'is_encrypted' => (bool) ($data['is_encrypted'] ?? false),
            'is_read_only' => (bool) ($data['is_read_only'] ?? false),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function validateState(Request $request, array $options): array
    {
        $data = $request->validate([
            'entity_type' => ['required', 'string', Rule::in(array_keys(app(DynamicEntityRegistry::class)->options()))],
            'workflow_code' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/'],
            'code' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/'],
            'label' => ['required', 'string', 'max:160'],
            'color' => ['nullable', 'string', 'max:30'],
            'state_type' => ['nullable', 'string', Rule::in(array_keys($options['stateTypes']))],
            'is_initial' => ['boolean'],
            'is_final' => ['boolean'],
            'allowed_transitions_csv' => ['nullable', 'string', 'max:1000'],
            'required_permission' => ['nullable', 'string', 'max:120'],
            'entry_validations_text' => ['nullable', 'string', 'max:3000'],
            'actions_text' => ['nullable', 'string', 'max:2000'],
            'exit_actions_text' => ['nullable', 'string', 'max:3000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['boolean'],
        ]);

        return [
            'entity_type' => $data['entity_type'],
            'workflow_code' => $data['workflow_code'] ?? null,
            'code' => $data['code'],
            'label' => $data['label'],
            'color' => $data['color'] ?? null,
            'state_type' => $data['state_type'] ?? (($data['is_initial'] ?? false) ? 'initial' : (($data['is_final'] ?? false) ? 'final' : 'intermediate')),
            'is_initial' => (bool) ($data['is_initial'] ?? false),
            'is_final' => (bool) ($data['is_final'] ?? false),
            'allowed_transitions' => BusinessTransversalConfiguration::listFromCsv($data['allowed_transitions_csv'] ?? null),
            'required_permission' => $data['required_permission'] ?? null,
            'entry_validations' => BusinessTransversalConfiguration::jsonFromText($data['entry_validations_text'] ?? null),
            'actions' => BusinessTransversalConfiguration::jsonFromText($data['actions_text'] ?? null),
            'exit_actions' => BusinessTransversalConfiguration::jsonFromText($data['exit_actions_text'] ?? null),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateWorkflowTransition(Request $request): array
    {
        $data = $request->validate([
            'workflow_definition_id' => ['required', 'integer', 'exists:workflow_definitions,id'],
            'from_state_code' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/'],
            'to_state_code' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/', 'different:from_state_code'],
            'label' => ['nullable', 'string', 'max:160'],
            'required_permission' => ['nullable', 'string', 'max:160'],
            'conditions_text' => ['nullable', 'string', 'max:3000'],
            'validations_text' => ['nullable', 'string', 'max:3000'],
            'actions_text' => ['nullable', 'string', 'max:3000'],
            'requires_reason' => ['boolean'],
            'is_reversible' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['boolean'],
        ]);

        $workflow = WorkflowDefinition::query()->find($data['workflow_definition_id']);
        $stateCodes = BusinessStateDefinition::query()
            ->where('entity_type', $workflow?->entity_type)
            ->where('is_active', true)
            ->pluck('code')
            ->all();

        if (! in_array($data['from_state_code'], $stateCodes, true) || ! in_array($data['to_state_code'], $stateCodes, true)) {
            throw ValidationException::withMessages([
                'from_state_code' => 'Los estados de origen y destino deben existir y estar activos para la entidad del flujo.',
            ]);
        }

        return [
            'workflow_definition_id' => $data['workflow_definition_id'],
            'from_state_code' => $data['from_state_code'],
            'to_state_code' => $data['to_state_code'],
            'label' => $data['label'] ?? null,
            'required_permission' => $data['required_permission'] ?? null,
            'conditions' => BusinessTransversalConfiguration::jsonFromText($data['conditions_text'] ?? null),
            'validations' => BusinessTransversalConfiguration::jsonFromText($data['validations_text'] ?? null),
            'actions' => BusinessTransversalConfiguration::jsonFromText($data['actions_text'] ?? null),
            'requires_reason' => (bool) ($data['requires_reason'] ?? false),
            'is_reversible' => (bool) ($data['is_reversible'] ?? false),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function validateResource(Request $request, array $options): array
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'assigned_worker_id' => ['nullable', 'integer', 'exists:workers,id'],
            'type' => ['required', 'string', Rule::in(array_keys($options['resourceTypes']))],
            'code' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:180'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:999999'],
            'status' => ['required', 'string', Rule::in(array_keys($options['resourceStatuses']))],
            'location' => ['nullable', 'string', 'max:180'],
            'maintenance_starts_at' => ['nullable', 'date'],
            'maintenance_ends_at' => ['nullable', 'date', 'after:maintenance_starts_at'],
            'availability_rules_text' => ['nullable', 'string', 'max:2000'],
            'metadata_text' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);

        if (! empty($data['branch_id']) && ! empty($data['assigned_worker_id'])) {
            $worker = Worker::query()->findOrFail($data['assigned_worker_id']);
            if ((int) $worker->branch_id !== (int) $data['branch_id']) {
                throw ValidationException::withMessages([
                    'assigned_worker_id' => 'El responsable debe pertenecer a la sucursal del recurso.',
                ]);
            }
        }

        return [
            'branch_id' => $data['branch_id'] ?? null,
            'assigned_worker_id' => $data['assigned_worker_id'] ?? null,
            'type' => $data['type'],
            'code' => $data['code'],
            'name' => $data['name'],
            'capacity' => $data['capacity'] ?? null,
            'status' => $data['status'],
            'location' => $data['location'] ?? null,
            'maintenance_starts_at' => $data['maintenance_starts_at'] ?? null,
            'maintenance_ends_at' => $data['maintenance_ends_at'] ?? null,
            'availability_rules' => BusinessTransversalConfiguration::jsonFromText($data['availability_rules_text'] ?? null),
            'metadata' => BusinessTransversalConfiguration::jsonFromText($data['metadata_text'] ?? null),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function validatePriceList(Request $request, array $options): array
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'code' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:180'],
            'channel' => ['required', 'string', Rule::in(array_keys($options['channels']))],
            'currency_code' => ['required', 'string', 'max:10'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'rules_text' => ['nullable', 'string', 'max:3000'],
            'is_active' => ['boolean'],
        ]);

        return [
            'branch_id' => $data['branch_id'] ?? null,
            'customer_id' => null,
            'code' => $data['code'],
            'name' => $data['name'],
            'channel' => $data['channel'],
            'currency_code' => strtoupper($data['currency_code']),
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'rules' => BusinessTransversalConfiguration::jsonFromText($data['rules_text'] ?? null),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function validateCommission(Request $request, array $options): array
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'role_name' => ['nullable', 'string', 'max:120'],
            'responsible_type' => ['nullable', 'string', Rule::in(array_keys($options['responsibleTypes']))],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'calculation_base' => ['required', 'string', Rule::in(array_keys($options['commissionBases']))],
            'type' => ['required', 'string', Rule::in(array_keys($options['commissionTypes']))],
            'value' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'conditions_text' => ['nullable', 'string', 'max:3000'],
            'is_active' => ['boolean'],
        ]);

        return [
            'branch_id' => $data['branch_id'] ?? null,
            'role_name' => $data['role_name'] ?? null,
            'responsible_type' => $data['responsible_type'] ?? null,
            'product_id' => $data['product_id'] ?? null,
            'calculation_base' => $data['calculation_base'],
            'type' => $data['type'],
            'value' => $data['value'],
            'conditions' => BusinessTransversalConfiguration::jsonFromText($data['conditions_text'] ?? null),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function validateNotification(Request $request, array $options): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/'],
            'name' => ['required', 'string', 'max:180'],
            'trigger' => ['required', 'string', Rule::in(array_keys($options['notificationTriggers']))],
            'channels_csv' => ['nullable', 'string', 'max:500'],
            'recipients_text' => ['nullable', 'string', 'max:2000'],
            'conditions_text' => ['nullable', 'string', 'max:3000'],
            'is_active' => ['boolean'],
        ]);

        return [
            'code' => $data['code'],
            'name' => $data['name'],
            'trigger' => $data['trigger'],
            'channels' => BusinessTransversalConfiguration::listFromCsv($data['channels_csv'] ?? 'system'),
            'recipients' => BusinessTransversalConfiguration::jsonFromText($data['recipients_text'] ?? null),
            'conditions' => BusinessTransversalConfiguration::jsonFromText($data['conditions_text'] ?? null),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateCurrency(Request $request): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:10'],
            'name' => ['required', 'string', 'max:120'],
            'symbol' => ['nullable', 'string', 'max:20'],
            'exchange_rate_to_base' => ['required', 'numeric', 'min:0.00000001', 'max:999999999'],
            'rounding_decimals' => ['required', 'integer', 'min:0', 'max:8'],
            'is_base' => ['boolean'],
            'cash_enabled' => ['boolean'],
            'is_active' => ['boolean'],
            'metadata_text' => ['nullable', 'string', 'max:2000'],
        ]);

        return [
            'code' => strtoupper($data['code']),
            'name' => $data['name'],
            'symbol' => $data['symbol'] ?? null,
            'exchange_rate_to_base' => $data['exchange_rate_to_base'],
            'rounding_decimals' => $data['rounding_decimals'],
            'is_base' => (bool) ($data['is_base'] ?? false),
            'cash_enabled' => (bool) ($data['cash_enabled'] ?? true),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'metadata' => BusinessTransversalConfiguration::jsonFromText($data['metadata_text'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function validatePrinter(Request $request, array $options): array
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'code' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:160'],
            'area' => ['required', 'string', Rule::in(array_keys($options['printerAreas']))],
            'paper_type' => ['required', 'string', Rule::in(array_keys($options['paperTypes']))],
            'thermal_width_mm' => ['nullable', 'integer', 'min:30', 'max:220'],
            'copies' => ['required', 'integer', 'min:1', 'max:10'],
            'auto_print' => ['boolean'],
            'settings_text' => ['nullable', 'string', 'max:3000'],
            'is_active' => ['boolean'],
        ]);

        return [
            'branch_id' => $data['branch_id'] ?? null,
            'code' => $data['code'],
            'name' => $data['name'],
            'area' => $data['area'],
            'paper_type' => $data['paper_type'],
            'thermal_width_mm' => $data['thermal_width_mm'] ?? null,
            'copies' => $data['copies'],
            'auto_print' => (bool) ($data['auto_print'] ?? false),
            'settings' => BusinessTransversalConfiguration::jsonFromText($data['settings_text'] ?? null),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function validateLicense(Request $request, array $options): array
    {
        $data = $request->validate([
            'module' => ['required', 'string', Rule::in(array_keys($options['modules']))],
            'is_enabled' => ['boolean'],
            'max_branches' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'max_users' => ['nullable', 'integer', 'min:1', 'max:999999'],
            'max_pos_terminals' => ['nullable', 'integer', 'min:1', 'max:99999'],
            'support_until' => ['nullable', 'date'],
            'metadata_text' => ['nullable', 'string', 'max:2000'],
        ]);

        return [
            'module' => $data['module'],
            'is_enabled' => (bool) ($data['is_enabled'] ?? true),
            'max_branches' => $data['max_branches'] ?? null,
            'max_users' => $data['max_users'] ?? null,
            'max_pos_terminals' => $data['max_pos_terminals'] ?? null,
            'support_until' => $data['support_until'] ?? null,
            'metadata' => BusinessTransversalConfiguration::jsonFromText($data['metadata_text'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function validateImportTemplate(Request $request, array $options): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/'],
            'name' => ['required', 'string', 'max:180'],
            'entity_type' => ['required', 'string', Rule::in(array_keys($options['importEntities']))],
            'required_columns_csv' => ['required', 'string', 'max:1000'],
            'optional_columns_csv' => ['nullable', 'string', 'max:1000'],
            'mapping_rules_text' => ['nullable', 'string', 'max:3000'],
            'validation_rules_text' => ['nullable', 'string', 'max:3000'],
            'is_active' => ['boolean'],
        ]);

        return [
            'code' => $data['code'],
            'name' => $data['name'],
            'entity_type' => $data['entity_type'],
            'required_columns' => BusinessTransversalConfiguration::listFromCsv($data['required_columns_csv']),
            'optional_columns' => BusinessTransversalConfiguration::listFromCsv($data['optional_columns_csv'] ?? null),
            'mapping_rules' => BusinessTransversalConfiguration::jsonFromText($data['mapping_rules_text'] ?? null),
            'validation_rules' => BusinessTransversalConfiguration::jsonFromText($data['validation_rules_text'] ?? null),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @return class-string<Model>
     */
    private function modelClass(string $section): string
    {
        return match ($section) {
            'entities' => DynamicEntity::class,
            'custom-fields' => CustomFieldDefinition::class,
            'workflows' => WorkflowDefinition::class,
            'states' => BusinessStateDefinition::class,
            'workflow-transitions' => WorkflowTransition::class,
            'resources' => ReservableResource::class,
            'price-lists' => PriceList::class,
            'commissions' => CommissionRule::class,
            'notifications' => NotificationRule::class,
            'currencies' => BusinessCurrency::class,
            'printers' => PrinterProfile::class,
            'licenses' => BusinessModuleLicense::class,
            'imports' => ImportProfileTemplate::class,
            default => abort(404),
        };
    }

    private function findModel(string $section, int $record): Model
    {
        $modelClass = $this->modelClass($section);

        /** @var Model $model */
        $model = $modelClass::query()->findOrFail($record);

        return $model;
    }

    private function sectionLabel(string $section): string
    {
        return match ($section) {
            'entities' => 'Entidad configurable',
            'custom-fields' => 'Campo personalizado',
            'workflows' => 'Flujo de estado',
            'states' => 'Estado personalizado',
            'workflow-transitions' => 'Transicion de flujo',
            'resources' => 'Recurso reservable',
            'price-lists' => 'Lista de precios',
            'commissions' => 'Regla de comision',
            'notifications' => 'Regla de notificacion',
            'currencies' => 'Moneda',
            'printers' => 'Perfil de impresora',
            'licenses' => 'Licencia de modulo',
            'imports' => 'Plantilla de importacion',
            default => 'Registro',
        };
    }

    /**
     * Mantiene defaults unicos sin mezclar cambios de datos dentro de la validacion.
     *
     * @param array<string, mixed> $payload
     */
    private function clearOtherExclusiveDefaults(string $section, array $payload, ?Model $current = null): void
    {
        if ($section !== 'currencies' || ($payload['is_base'] ?? false) !== true) {
            if ($section === 'workflows' && ($payload['is_default'] ?? false) === true) {
                WorkflowDefinition::query()
                    ->when($current, fn ($query) => $query->whereKeyNot($current->getKey()))
                    ->where('entity_type', $payload['entity_type'])
                    ->update(['is_default' => false]);
            }

            return;
        }

        BusinessCurrency::query()
            ->when($current, fn ($query) => $query->whereKeyNot($current->getKey()))
            ->update(['is_base' => false]);
    }
}
