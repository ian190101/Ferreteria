<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Modules\SystemSuperadmin\Models\CalculationFormula;
use App\Modules\SystemSuperadmin\Models\CustomFieldDefinition;
use App\Modules\SystemSuperadmin\Models\DynamicEntity;
use App\Modules\SystemSuperadmin\Models\DynamicFormDefinition;
use App\Modules\SystemSuperadmin\Models\DynamicFormFieldRule;
use App\Modules\SystemSuperadmin\Models\DynamicRelationshipDefinition;
use App\Modules\SystemSuperadmin\Models\WorkflowDefinition;
use App\Modules\SystemSuperadmin\Models\WorkflowTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BusinessSpecializedBlueprintInstaller
{
    public function __construct(
        private readonly BusinessSpecializedBlueprintFactory $blueprints,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function installForPreset(string $presetName): array
    {
        $blueprint = $this->blueprints->forPreset($presetName);

        if (! $blueprint) {
            throw ValidationException::withMessages([
                'preset' => 'El preset no tiene blueprint especializado configurado.',
            ]);
        }

        return DB::transaction(fn (): array => $this->install($blueprint));
    }

    /**
     * @param array<string, mixed> $blueprint
     * @return array<string, int>
     */
    private function install(array $blueprint): array
    {
        $summary = [
            'entities' => 0,
            'fields' => 0,
            'relationships' => 0,
            'forms' => 0,
            'form_fields' => 0,
            'workflows' => 0,
            'transitions' => 0,
            'formulas' => 0,
        ];

        foreach ($blueprint['entities'] ?? [] as $entity) {
            DynamicEntity::query()->updateOrCreate(
                ['code' => $entity['code']],
                $entity,
            );
            $summary['entities']++;
        }

        foreach ($blueprint['fields'] ?? [] as $field) {
            CustomFieldDefinition::query()->updateOrCreate(
                ['entity_type' => $field['entity_type'], 'code' => $field['code']],
                $field,
            );
            $summary['fields']++;
        }

        foreach ($blueprint['relationships'] ?? [] as $relationship) {
            DynamicRelationshipDefinition::query()->updateOrCreate(
                ['code' => $relationship['code']],
                $relationship,
            );
            $summary['relationships']++;
        }

        foreach ($blueprint['forms'] ?? [] as $formBlueprint) {
            $fields = $formBlueprint['fields'] ?? [];
            unset($formBlueprint['fields']);

            $form = DynamicFormDefinition::query()->updateOrCreate(
                ['entity_type' => $formBlueprint['entity_type'], 'code' => $formBlueprint['code']],
                $formBlueprint,
            );
            $summary['forms']++;

            foreach (array_values($fields) as $index => $fieldCode) {
                DynamicFormFieldRule::query()->updateOrCreate(
                    ['dynamic_form_definition_id' => $form->id, 'field_code' => $fieldCode],
                    [
                        'is_required' => true,
                        'is_visible' => true,
                        'is_read_only' => false,
                        'sort_order' => ($index + 1) * 10,
                        'is_active' => true,
                    ],
                );
                $summary['form_fields']++;
            }
        }

        foreach ($blueprint['workflows'] ?? [] as $workflowBlueprint) {
            $transitions = $workflowBlueprint['transitions'] ?? [];
            unset($workflowBlueprint['transitions']);

            $workflow = WorkflowDefinition::query()->updateOrCreate(
                ['entity_type' => $workflowBlueprint['entity_type'], 'code' => $workflowBlueprint['code']],
                $workflowBlueprint,
            );
            $summary['workflows']++;

            foreach (array_values($transitions) as $index => $transition) {
                WorkflowTransition::query()->updateOrCreate(
                    [
                        'workflow_definition_id' => $workflow->id,
                        'from_state_code' => $transition['from_state_code'],
                        'to_state_code' => $transition['to_state_code'],
                    ],
                    [...$transition, 'sort_order' => ($index + 1) * 10],
                );
                $summary['transitions']++;
            }
        }

        foreach ($blueprint['formulas'] ?? [] as $formula) {
            CalculationFormula::query()->updateOrCreate(
                ['code' => $formula['code']],
                $formula,
            );
            $summary['formulas']++;
        }

        return $summary;
    }
}
