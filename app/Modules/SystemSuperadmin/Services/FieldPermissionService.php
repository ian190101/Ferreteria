<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Models\User;
use App\Modules\SystemSuperadmin\Models\CustomFieldDefinition;
use Illuminate\Support\Collection;

class FieldPermissionService
{
    public function canView(CustomFieldDefinition $definition, ?User $user = null, array $context = []): bool
    {
        return $this->decision($definition, $user, 'view', $context);
    }

    public function canEdit(CustomFieldDefinition $definition, ?User $user = null, array $context = []): bool
    {
        if ($definition->is_read_only) {
            return false;
        }

        return $this->decision($definition, $user, 'edit', $context);
    }

    public function canExport(CustomFieldDefinition $definition, ?User $user = null, array $context = []): bool
    {
        if (! $definition->is_exportable) {
            return false;
        }

        return $this->decision($definition, $user, 'export', $context);
    }

    public function canUseOnSurface(CustomFieldDefinition $definition, string $surface, ?User $user = null, array $context = []): bool
    {
        return match ($surface) {
            'export' => $this->canExport($definition, $user, $context),
            'form' => $this->canView($definition, $user, $context) && $this->canEdit($definition, $user, $context),
            default => $this->canView($definition, $user, $context),
        };
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function filterValuesForSurface(string $entityType, array $values, string $surface, ?User $user = null, array $context = []): array
    {
        if ($values === []) {
            return [];
        }

        $action = match ($surface) {
            'export' => 'export',
            'form_edit', 'edit' => 'edit',
            default => 'view',
        };

        $definitions = $this->definitionsForValues($entityType, array_keys($values));
        $filtered = [];

        foreach ($values as $code => $value) {
            /** @var CustomFieldDefinition|null $definition */
            $definition = $definitions->get($code);

            if (! $definition || ! $this->canUseOnSurface($definition, $surface, $user, $context)) {
                continue;
            }

            $filtered[$code] = $value;
            $this->auditSensitiveAccess($definition, $action, $user, $context);
        }

        return $filtered;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function recordSensitiveFieldChange(
        CustomFieldDefinition $definition,
        ?User $user = null,
        array $context = [],
        mixed $before = null,
        mixed $after = null,
    ): void {
        if (! $definition->is_sensitive) {
            return;
        }

        app(OperationalTraceService::class)->record(
            $definition->entity_type,
            isset($context['entity_id']) ? (int) $context['entity_id'] : null,
            'sensitive_field_changed',
            $user,
            isset($context['branch_id']) ? (int) $context['branch_id'] : null,
            [
                'field' => $definition->code,
                'label' => $definition->label,
                'before_present' => $before !== null,
                'after_present' => $after !== null,
            ],
            $context,
            'field_permissions',
        );
    }

    private function decision(CustomFieldDefinition $definition, ?User $user, string $action, array $context): bool
    {
        $payload = ActiveBusinessProfile::payload();
        $fieldPermissions = $payload['field_permissions'] ?? [];

        if (! (bool) data_get($payload, 'feature_flags.field_permissions_engine', false)
            || ! (bool) data_get($payload, 'capabilities.uses_field_level_permissions', false)) {
            return ! $definition->is_sensitive;
        }

        $rules = collect($fieldPermissions['rules'] ?? [])
            ->filter(fn (array $rule) => $this->matchesRule($rule, $definition, $user, $action, $context));

        if ($rules->contains(fn (array $rule) => ($rule['effect'] ?? 'allow') === 'deny')) {
            return false;
        }

        if ($rules->contains(fn (array $rule) => ($rule['effect'] ?? 'allow') === 'allow')) {
            return true;
        }

        if (! $definition->is_sensitive) {
            return true;
        }

        return match ($fieldPermissions['default_sensitive_visibility'] ?? 'deny') {
            'allow' => true,
            'read_only' => $action === 'view',
            default => false,
        };
    }

    private function matchesRule(array $rule, CustomFieldDefinition $definition, ?User $user, string $action, array $context): bool
    {
        if (! in_array($action, (array) ($rule['actions'] ?? ['view']), true)) {
            return false;
        }

        if (! $this->matchesWildcard($rule['entity'] ?? '*', $definition->entity_type)) {
            return false;
        }

        if (! $this->matchesWildcard($rule['field'] ?? '*', $definition->code)) {
            return false;
        }

        if (isset($rule['roles']) && ! $this->userHasAnyRole($user, (array) $rule['roles'])) {
            return false;
        }

        if (isset($rule['users']) && ! in_array((int) $user?->id, array_map('intval', (array) $rule['users']), true)) {
            return false;
        }

        if (isset($rule['branches']) && ! in_array((int) ($context['branch_id'] ?? $user?->branch_id), array_map('intval', (array) $rule['branches']), true)) {
            return false;
        }

        if (isset($rule['flows']) && ! in_array((string) ($context['flow'] ?? ''), (array) $rule['flows'], true)) {
            return false;
        }

        if (isset($rule['states']) && ! in_array((string) ($context['state'] ?? ''), (array) $rule['states'], true)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<int, string|int> $codes
     * @return Collection<string, CustomFieldDefinition>
     */
    private function definitionsForValues(string $entityType, array $codes): Collection
    {
        return CustomFieldDefinition::query()
            ->where('entity_type', $entityType)
            ->where('is_active', true)
            ->whereIn('code', array_map('strval', $codes))
            ->get()
            ->keyBy('code');
    }

    /**
     * @param array<string, mixed> $context
     */
    private function auditSensitiveAccess(CustomFieldDefinition $definition, string $action, ?User $user, array $context): void
    {
        if (! $definition->is_sensitive || ! (bool) data_get(ActiveBusinessProfile::payload(), 'governance.audit_sensitive_reads', false)) {
            return;
        }

        if (! in_array($action, ['view', 'export'], true)) {
            return;
        }

        app(OperationalTraceService::class)->record(
            $definition->entity_type,
            isset($context['entity_id']) ? (int) $context['entity_id'] : null,
            $action === 'export' ? 'sensitive_field_exported' : 'sensitive_field_viewed',
            $user,
            isset($context['branch_id']) ? (int) $context['branch_id'] : null,
            [
                'field' => $definition->code,
                'label' => $definition->label,
                'action' => $action,
            ],
            $context,
            'field_permissions',
        );
    }

    private function matchesWildcard(string $expected, string $actual): bool
    {
        return $expected === '*' || $expected === $actual;
    }

    /**
     * @param array<int, string> $roles
     */
    private function userHasAnyRole(?User $user, array $roles): bool
    {
        if (! $user) {
            return false;
        }

        return collect($roles)->contains(fn (string $role) => $user->hasRole($role));
    }
}
