<?php

namespace App\Modules\SystemSuperadmin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SystemSuperadmin\Models\BusinessCurrency;
use App\Modules\SystemSuperadmin\Models\BusinessModuleLicense;
use App\Modules\SystemSuperadmin\Models\BusinessStateDefinition;
use App\Modules\SystemSuperadmin\Models\CommissionRule;
use App\Modules\SystemSuperadmin\Models\CustomFieldDefinition;
use App\Modules\SystemSuperadmin\Models\ImportProfileTemplate;
use App\Modules\SystemSuperadmin\Models\NotificationRule;
use App\Modules\SystemSuperadmin\Models\PriceList;
use App\Modules\SystemSuperadmin\Models\PrinterProfile;
use App\Modules\SystemSuperadmin\Models\ReservableResource;
use App\Modules\SystemSuperadmin\Services\BusinessTransversalConfiguration;
use App\Modules\SystemSuperadmin\Services\BusinessTransversalDataService;
use App\Support\SystemCacheInvalidator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
            $this->clearOtherBaseCurrencies($section, $payload);

            /** @var class-string<Model> $modelClass */
            $modelClass::query()->create($payload);
        });

        SystemCacheInvalidator::bumpOperational();

        return back()->with('success', $this->sectionLabel($section).' guardado correctamente.');
    }

    public function update(Request $request, string $section, int $record): RedirectResponse
    {
        $model = $this->findModel($section, $record);
        $payload = $this->validatedPayload($request, $section);

        DB::transaction(function () use ($model, $payload, $section): void {
            $this->clearOtherBaseCurrencies($section, $payload, $model);

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
    private function validatedPayload(Request $request, string $section): array
    {
        $options = BusinessTransversalConfiguration::options();

        return match ($section) {
            'custom-fields' => $this->validateCustomField($request, $options),
            'states' => $this->validateState($request, $options),
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
    private function validateCustomField(Request $request, array $options): array
    {
        $data = $request->validate([
            'entity_type' => ['required', 'string', Rule::in(array_keys($options['entities']))],
            'code' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/'],
            'label' => ['required', 'string', 'max:160'],
            'type' => ['required', 'string', Rule::in(array_keys($options['fieldTypes']))],
            'options_csv' => ['nullable', 'string', 'max:1000'],
            'validation_rules_text' => ['nullable', 'string', 'max:2000'],
            'is_required' => ['boolean'],
            'visible_in_forms' => ['boolean'],
            'visible_in_documents' => ['boolean'],
            'visible_in_reports' => ['boolean'],
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
            'type' => $data['type'],
            'options' => BusinessTransversalConfiguration::listFromCsv($data['options_csv'] ?? null),
            'validation_rules' => BusinessTransversalConfiguration::jsonFromText($data['validation_rules_text'] ?? null),
            'is_required' => (bool) ($data['is_required'] ?? false),
            'visible_in_forms' => (bool) ($data['visible_in_forms'] ?? true),
            'visible_in_documents' => (bool) ($data['visible_in_documents'] ?? false),
            'visible_in_reports' => (bool) ($data['visible_in_reports'] ?? false),
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
            'entity_type' => ['required', 'string', Rule::in(array_keys($options['entities']))],
            'code' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/'],
            'label' => ['required', 'string', 'max:160'],
            'color' => ['nullable', 'string', 'max:30'],
            'is_initial' => ['boolean'],
            'is_final' => ['boolean'],
            'allowed_transitions_csv' => ['nullable', 'string', 'max:1000'],
            'required_permission' => ['nullable', 'string', 'max:120'],
            'actions_text' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['boolean'],
        ]);

        return [
            'entity_type' => $data['entity_type'],
            'code' => $data['code'],
            'label' => $data['label'],
            'color' => $data['color'] ?? null,
            'is_initial' => (bool) ($data['is_initial'] ?? false),
            'is_final' => (bool) ($data['is_final'] ?? false),
            'allowed_transitions' => BusinessTransversalConfiguration::listFromCsv($data['allowed_transitions_csv'] ?? null),
            'required_permission' => $data['required_permission'] ?? null,
            'actions' => BusinessTransversalConfiguration::jsonFromText($data['actions_text'] ?? null),
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
            'type' => ['required', 'string', Rule::in(array_keys($options['resourceTypes']))],
            'code' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:180'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:999999'],
            'availability_rules_text' => ['nullable', 'string', 'max:2000'],
            'metadata_text' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);

        return [
            'branch_id' => $data['branch_id'] ?? null,
            'type' => $data['type'],
            'code' => $data['code'],
            'name' => $data['name'],
            'capacity' => $data['capacity'] ?? null,
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
            'custom-fields' => CustomFieldDefinition::class,
            'states' => BusinessStateDefinition::class,
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
            'custom-fields' => 'Campo personalizado',
            'states' => 'Estado personalizado',
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
     * Mantiene una sola moneda base sin mezclar cambios de datos dentro de la validacion.
     *
     * @param array<string, mixed> $payload
     */
    private function clearOtherBaseCurrencies(string $section, array $payload, ?Model $current = null): void
    {
        if ($section !== 'currencies' || ($payload['is_base'] ?? false) !== true) {
            return;
        }

        BusinessCurrency::query()
            ->when($current, fn ($query) => $query->whereKeyNot($current->getKey()))
            ->update(['is_base' => false]);
    }
}
