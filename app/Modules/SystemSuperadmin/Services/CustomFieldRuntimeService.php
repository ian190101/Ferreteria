<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Modules\SystemSuperadmin\Models\CustomFieldDefinition;
use App\Models\User;
use App\Support\SystemCacheInvalidator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CustomFieldRuntimeService
{
    public function __construct(private readonly FieldPermissionService $fieldPermissions)
    {
    }

    /**
     * @return array<int, CustomFieldDefinition>
     */
    public function definitionsFor(string $entityType, bool $onlyActive = true): array
    {
        if (! app(DynamicEntityRegistry::class)->isUsable($entityType)) {
            return [];
        }

        return Cache::remember($this->cacheKey('definitions', [$entityType, $onlyActive ? 'active' : 'all']), $this->cacheTtl(), fn (): array => CustomFieldDefinition::query()
            ->where('entity_type', $entityType)
            ->when($onlyActive, fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get()
            ->all());
    }

    /**
     * @return array<int, CustomFieldDefinition>
     */
    public function definitionsForSurface(string $entityType, string $surface, bool $includeSensitive = false, ?User $user = null, array $context = []): array
    {
        $column = match ($surface) {
            'table' => 'visible_in_table',
            'document' => 'visible_in_documents',
            'report' => 'visible_in_reports',
            'export' => 'is_exportable',
            default => 'visible_in_forms',
        };

        return collect($this->definitionsFor($entityType))
            ->filter(fn (CustomFieldDefinition $definition) => (bool) $definition->{$column})
            ->filter(fn (CustomFieldDefinition $definition) => $includeSensitive || ! $definition->is_sensitive || $this->fieldPermissions->canUseOnSurface($definition, $surface, $user, $context))
            ->values()
            ->all();
    }

    /**
     * @return array<int, CustomFieldDefinition>
     */
    public function editableDefinitionsFor(string $entityType, ?User $user = null, array $context = []): array
    {
        return collect($this->definitionsFor($entityType))
            ->filter(fn (CustomFieldDefinition $definition) => (bool) $definition->visible_in_forms)
            ->filter(fn (CustomFieldDefinition $definition) => $this->fieldPermissions->canEdit($definition, $user, $context))
            ->values()
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
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function valuesWithDefaults(string $entityType, array $values): array
    {
        foreach ($this->definitionsFor($entityType) as $definition) {
            if (! array_key_exists($definition->code, $values) && $definition->default_value !== null) {
                $values[$definition->code] = $definition->default_value['valor'] ?? $definition->default_value;
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function prepareValuesForStorage(string $entityType, array $values): array
    {
        $values = $this->validateValues($entityType, $this->valuesWithDefaults($entityType, $values));
        $definitions = collect($this->definitionsFor($entityType))->keyBy('code');

        return collect($values)
            ->mapWithKeys(function (mixed $value, string $code) use ($definitions): array {
                /** @var CustomFieldDefinition|null $definition */
                $definition = $definitions->get($code);

                if ($definition?->is_encrypted) {
                    return [$code => ['encrypted' => true, 'value' => Crypt::encryptString(json_encode($value, JSON_UNESCAPED_UNICODE))]];
                }

                return [$code => $value];
            })
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function rulesFor(CustomFieldDefinition $definition): array
    {
        $rules = $definition->is_required && ! $definition->is_read_only ? ['required'] : ['nullable'];

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

        if (in_array($definition->type, ['number', 'decimal', 'currency', 'percentage'], true)) {
            if ($definition->min_value !== null) {
                $rules[] = 'min:'.(string) $definition->min_value;
            }

            if ($definition->max_value !== null) {
                $rules[] = 'max:'.(string) $definition->max_value;
            }
        }

        if ($definition->type === 'select' && filled($definition->options)) {
            $rules[] = 'in:'.implode(',', $definition->options);
        }

        if ($definition->type === 'multi_select' && filled($definition->options)) {
            $rules[] = 'array';
            $rules[] = function (string $attribute, mixed $value, \Closure $fail) use ($definition): void {
                $invalid = collect((array) $value)->diff($definition->options ?? []);

                if ($invalid->isNotEmpty()) {
                    $fail('El campo :attribute contiene opciones no permitidas.');
                }
            };
        }

        foreach (($definition->validation_rules ?? []) as $rule) {
            if (is_string($rule)) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    private function cacheKey(string $scope, array $parts = []): string
    {
        return 'business-custom-fields:'.SystemCacheInvalidator::operationalVersion().':'.$scope.':'.implode(':', $parts);
    }

    private function cacheTtl(): \DateTimeInterface
    {
        $minutes = (int) data_get(ActiveBusinessProfile::payload(), 'performance.cache_capabilities_minutes', 60);

        return now()->addMinutes(max($minutes, 1));
    }
}
