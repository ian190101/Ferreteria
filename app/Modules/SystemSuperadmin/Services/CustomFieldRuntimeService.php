<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Modules\SystemSuperadmin\Models\CustomFieldDefinition;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CustomFieldRuntimeService
{
    /**
     * @return array<int, CustomFieldDefinition>
     */
    public function definitionsFor(string $entityType, bool $onlyActive = true): array
    {
        return CustomFieldDefinition::query()
            ->where('entity_type', $entityType)
            ->when($onlyActive, fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get()
            ->all();
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validateValues(string $entityType, array $values): array
    {
        $rules = [];
        $attributes = [];

        foreach ($this->definitionsFor($entityType) as $definition) {
            $fieldRules = $this->rulesFor($definition);
            $rules[$definition->code] = $fieldRules;
            $attributes[$definition->code] = $definition->label;
        }

        return Validator::make($values, $rules, [], $attributes)->validate();
    }

    /**
     * @return array<int, string>
     */
    private function rulesFor(CustomFieldDefinition $definition): array
    {
        $rules = $definition->is_required ? ['required'] : ['nullable'];

        $rules[] = match ($definition->type) {
            'number', 'decimal' => 'numeric',
            'date' => 'date',
            'datetime' => 'date',
            'boolean' => 'boolean',
            'select' => 'string',
            'multi_select' => 'array',
            'file', 'image' => 'string',
            default => 'string',
        };

        foreach (($definition->validation_rules ?? []) as $rule) {
            if (is_string($rule)) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }
}
