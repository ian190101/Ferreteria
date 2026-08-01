<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Models\User;
use App\Modules\SystemSuperadmin\Models\CustomFieldDefinition;
use App\Modules\SystemSuperadmin\Models\DynamicFormDefinition;
use App\Modules\SystemSuperadmin\Models\DynamicFormFieldRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DynamicFormService
{
    public function __construct(
        private readonly CustomFieldRuntimeService $fields,
        private readonly FieldPermissionService $fieldPermissions,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, DynamicFormDefinition>
     */
    public function formsFor(string $entityType, array $context = [], bool $onlyRuntimeEnabled = true): array
    {
        if ($onlyRuntimeEnabled && ! $this->runtimeEnabled()) {
            return [];
        }

        if (! app(DynamicEntityRegistry::class)->isUsable($entityType)) {
            return [];
        }

        return DynamicFormDefinition::query()
            ->where('entity_type', $entityType)
            ->where('is_active', true)
            ->when(array_key_exists('flow', $context), fn ($query) => $query->where(fn ($scoped) => $scoped->whereNull('flow')->orWhere('flow', $context['flow'])))
            ->when(array_key_exists('workflow_code', $context), fn ($query) => $query->where(fn ($scoped) => $scoped->whereNull('workflow_code')->orWhere('workflow_code', $context['workflow_code'])))
            ->when(array_key_exists('state_code', $context), fn ($query) => $query->where(fn ($scoped) => $scoped->whereNull('state_code')->orWhere('state_code', $context['state_code'])))
            ->when(array_key_exists('surface', $context), fn ($query) => $query->where('surface', $context['surface']))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->all();
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    public function resolve(string $entityType, string $code, ?User $user = null, array $context = []): ?array
    {
        if (! $this->runtimeEnabled()) {
            return null;
        }

        /** @var DynamicFormDefinition|null $form */
        $form = collect($this->formsFor($entityType, $context))
            ->first(fn (DynamicFormDefinition $definition) => $definition->code === $code);

        if (! $form) {
            return null;
        }

        $fields = $this->resolvedFieldsFor($form, $user, $context);

        return [
            'id' => $form->id,
            'entity_type' => $form->entity_type,
            'code' => $form->code,
            'name' => $form->name,
            'flow' => $form->flow,
            'workflow_code' => $form->workflow_code,
            'state_code' => $form->state_code,
            'surface' => $form->surface,
            'description' => $form->description,
            'submit_label' => $form->submit_label,
            'layout' => $form->layout ?? [],
            'validations' => $form->validations ?? [],
            'fields' => $fields,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, array<int, mixed>>
     */
    public function rulesForValidation(string $entityType, string $formCode, ?User $user = null, array $context = []): array
    {
        $resolved = $this->resolve($entityType, $formCode, $user, $context);

        if (! $resolved) {
            return [];
        }

        return collect($resolved['fields'])
            ->filter(fn (array $field) => (bool) $field['is_visible'] && ! (bool) $field['is_read_only'])
            ->mapWithKeys(fn (array $field) => [$field['code'] => $field['rules']])
            ->all();
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(string $entityType, string $formCode, array $values, ?User $user = null, array $context = []): array
    {
        $rules = $this->rulesForValidation($entityType, $formCode, $user, $context);

        if ($rules === []) {
            throw ValidationException::withMessages([
                'form' => 'El formulario dinamico no esta disponible para el perfil actual.',
            ]);
        }

        return Validator::make($values, $rules)->validate();
    }

    public function runtimeEnabled(): bool
    {
        return ActiveBusinessProfile::featureEnabled('dynamic_forms_engine')
            && ActiveBusinessProfile::capable('uses_dynamic_forms')
            && ActiveBusinessProfile::featureEnabled('dynamic_fields_engine')
            && ActiveBusinessProfile::capable('uses_dynamic_fields')
            && ActiveBusinessProfile::featureEnabled('dynamic_entities_engine')
            && ActiveBusinessProfile::capable('uses_dynamic_entities');
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    private function resolvedFieldsFor(DynamicFormDefinition $form, ?User $user = null, array $context = []): array
    {
        $definitions = collect($this->fields->definitionsFor($form->entity_type))->keyBy('code');

        return DynamicFormFieldRule::query()
            ->where('dynamic_form_definition_id', $form->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('field_code')
            ->get()
            ->map(function (DynamicFormFieldRule $rule) use ($definitions, $user, $context): ?array {
                /** @var CustomFieldDefinition|null $definition */
                $definition = $definitions->get($rule->field_code);

                if (! $definition || ! $definition->visible_in_forms || ! $this->fieldPermissions->canView($definition, $user, $context)) {
                    return null;
                }

                $isEditable = ! $rule->is_read_only && $this->fieldPermissions->canEdit($definition, $user, $context);
                $rules = $this->rulesForField($definition, $rule, $isEditable);

                return [
                    'code' => $definition->code,
                    'label' => $rule->label_override ?: $definition->label,
                    'type' => $definition->type,
                    'group' => $definition->group,
                    'help_text' => $rule->help_text ?: $definition->help_text,
                    'placeholder' => $rule->placeholder ?: $definition->placeholder,
                    'is_required' => $rule->is_required || $definition->is_required,
                    'is_visible' => $rule->is_visible,
                    'is_read_only' => ! $isEditable,
                    'default_value' => $rule->default_value ?? $definition->default_value,
                    'options' => $rule->options_override ?? $definition->options ?? [],
                    'visibility_conditions' => $rule->visibility_conditions ?? [],
                    'required_conditions' => $rule->required_conditions ?? [],
                    'rules' => $rules,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, mixed>
     */
    private function rulesForField(CustomFieldDefinition $definition, DynamicFormFieldRule $rule, bool $isEditable): array
    {
        if (! $rule->is_visible || ! $isEditable) {
            return ['nullable'];
        }

        $rules = ($rule->is_required || $definition->is_required) ? ['required'] : ['nullable'];

        $rules[] = match ($definition->type) {
            'number', 'decimal', 'currency', 'percentage' => 'numeric',
            'date' => 'date',
            'time' => 'date_format:H:i',
            'datetime' => 'date',
            'boolean' => 'boolean',
            'select' => 'string',
            'multi_select' => 'array',
            'email' => 'email',
            'phone' => 'string',
            'identity_document', 'nit', 'barcode' => 'string',
            'entity_relation' => 'integer',
            'file', 'image' => 'string',
            default => 'string',
        };

        foreach (($definition->validation_rules ?? []) as $validationRule) {
            if (is_string($validationRule)) {
                $rules[] = $validationRule;
            }
        }

        foreach (($rule->validation_rules ?? []) as $validationRule) {
            if (is_string($validationRule)) {
                $rules[] = $validationRule;
            }
        }

        return $rules;
    }
}
