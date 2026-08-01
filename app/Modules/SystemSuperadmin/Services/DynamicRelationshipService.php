<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Modules\SystemSuperadmin\Models\DynamicRelationshipDefinition;
use App\Modules\SystemSuperadmin\Models\DynamicRelationshipValue;
use Illuminate\Validation\ValidationException;

class DynamicRelationshipService
{
    /**
     * @return array<int, DynamicRelationshipDefinition>
     */
    public function definitionsFor(string $sourceEntityType, bool $onlyRuntimeEnabled = true): array
    {
        if ($onlyRuntimeEnabled && ! $this->runtimeEnabled()) {
            return [];
        }

        if (! app(DynamicEntityRegistry::class)->isUsable($sourceEntityType)) {
            return [];
        }

        return DynamicRelationshipDefinition::query()
            ->where('source_entity_type', $sourceEntityType)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get()
            ->filter(fn (DynamicRelationshipDefinition $definition) => app(DynamicEntityRegistry::class)->isUsable($definition->target_entity_type))
            ->values()
            ->all();
    }

    public function findUsable(string $sourceEntityType, string $code): ?DynamicRelationshipDefinition
    {
        return collect($this->definitionsFor($sourceEntityType))
            ->first(fn (DynamicRelationshipDefinition $definition) => $definition->code === $code);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function attach(DynamicRelationshipDefinition $definition, string|int $sourceRecordId, string|int $targetRecordId, array $payload = []): DynamicRelationshipValue
    {
        if (! $this->runtimeEnabled()) {
            throw ValidationException::withMessages([
                'relationship' => 'El motor de relaciones dinamicas no esta activo para el perfil actual.',
            ]);
        }

        $this->validateDefinitionIsUsable($definition);
        $this->validateMultiplicity($definition, (string) $sourceRecordId, (string) $targetRecordId);

        return DynamicRelationshipValue::query()->updateOrCreate(
            [
                'dynamic_relationship_definition_id' => $definition->id,
                'source_record_id' => (string) $sourceRecordId,
                'target_record_id' => (string) $targetRecordId,
            ],
            [
                'source_entity_type' => $definition->source_entity_type,
                'target_entity_type' => $definition->target_entity_type,
                'payload' => $payload,
                'is_active' => true,
            ]
        );
    }

    /**
     * @return array<int, DynamicRelationshipValue>
     */
    public function valuesFor(string $sourceEntityType, string|int $sourceRecordId, ?string $relationshipCode = null): array
    {
        if (! $this->runtimeEnabled()) {
            return [];
        }

        return DynamicRelationshipValue::query()
            ->with('definition:id,code,label,source_entity_type,target_entity_type,type')
            ->where('source_entity_type', $sourceEntityType)
            ->where('source_record_id', (string) $sourceRecordId)
            ->where('is_active', true)
            ->when($relationshipCode, fn ($query) => $query->whereHas(
                'definition',
                fn ($definitionQuery) => $definitionQuery->where('code', $relationshipCode)->where('is_active', true)
            ))
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function runtimeEnabled(): bool
    {
        return ActiveBusinessProfile::featureEnabled('dynamic_relationships_engine')
            && ActiveBusinessProfile::capable('uses_dynamic_relationships')
            && ActiveBusinessProfile::featureEnabled('dynamic_entities_engine')
            && ActiveBusinessProfile::capable('uses_dynamic_entities');
    }

    private function validateDefinitionIsUsable(DynamicRelationshipDefinition $definition): void
    {
        if (! $definition->is_active
            || ! app(DynamicEntityRegistry::class)->isUsable($definition->source_entity_type)
            || ! app(DynamicEntityRegistry::class)->isUsable($definition->target_entity_type)) {
            throw ValidationException::withMessages([
                'relationship' => 'La relacion dinamica no esta activa o usa entidades inactivas.',
            ]);
        }
    }

    private function validateMultiplicity(DynamicRelationshipDefinition $definition, string $sourceRecordId, string $targetRecordId): void
    {
        $currentQuery = DynamicRelationshipValue::query()
            ->where('dynamic_relationship_definition_id', $definition->id)
            ->where('source_record_id', $sourceRecordId)
            ->where('target_record_id', '!=', $targetRecordId)
            ->where('is_active', true);

        if (! $definition->allows_multiple && $currentQuery->exists()) {
            throw ValidationException::withMessages([
                'relationship' => 'Esta relacion permite un solo destino activo para el registro origen.',
            ]);
        }

        if ($definition->max_items !== null && $currentQuery->count() >= $definition->max_items) {
            throw ValidationException::withMessages([
                'relationship' => 'La relacion alcanzo el maximo de destinos permitidos para este origen.',
            ]);
        }
    }
}
