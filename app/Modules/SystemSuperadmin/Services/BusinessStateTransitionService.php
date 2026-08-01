<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Models\User;
use App\Modules\SystemSuperadmin\Models\BusinessStateDefinition;
use App\Modules\SystemSuperadmin\Models\WorkflowDefinition;
use App\Modules\SystemSuperadmin\Models\WorkflowTransition;

class BusinessStateTransitionService
{
    public function canTransition(string $entityType, string $fromCode, string $toCode, ?User $user = null, ?string $workflowCode = null): bool
    {
        return $this->transitionCheck($entityType, $fromCode, $toCode, $user, $workflowCode)['allowed'];
    }

    /**
     * @return array{allowed:bool, reason:?string, transition:?WorkflowTransition, actions:array<int|string, mixed>, validations:array<int|string, mixed>}
     */
    public function transitionCheck(string $entityType, string $fromCode, string $toCode, ?User $user = null, ?string $workflowCode = null): array
    {
        $from = BusinessStateDefinition::query()
            ->where('entity_type', $entityType)
            ->where('code', $fromCode)
            ->where('is_active', true)
            ->first();

        $to = BusinessStateDefinition::query()
            ->where('entity_type', $entityType)
            ->where('code', $toCode)
            ->where('is_active', true)
            ->first();

        if (! $from || ! $to) {
            return $this->result(false, 'Estado origen o destino no existe.');
        }

        $workflow = $this->workflowFor($entityType, $workflowCode ?? $from->workflow_code ?? $to->workflow_code);

        if ($workflow) {
            $transition = WorkflowTransition::query()
                ->where('workflow_definition_id', $workflow->id)
                ->where('from_state_code', $fromCode)
                ->where('to_state_code', $toCode)
                ->where('is_active', true)
                ->first();

            if (! $transition) {
                return $this->result(false, 'La transicion no esta permitida en este flujo.');
            }

            if (filled($transition->required_permission) && ! $user?->can($transition->required_permission)) {
                return $this->result(false, 'No tienes permiso para realizar esta transicion.', $transition);
            }

            if (filled($to->required_permission) && ! $user?->can($to->required_permission)) {
                return $this->result(false, 'No tienes permiso para entrar al estado destino.', $transition);
            }

            return $this->result(true, null, $transition);
        }

        $allowed = $from->allowed_transitions ?? [];

        if ($allowed !== [] && ! in_array($toCode, $allowed, true)) {
            return $this->result(false, 'La transicion no esta permitida.');
        }

        if (filled($to->required_permission) && ! $user?->can($to->required_permission)) {
            return $this->result(false, 'No tienes permiso para entrar al estado destino.');
        }

        return $this->result(true);
    }

    /**
     * @return array<int, BusinessStateDefinition>
     */
    public function initialStates(string $entityType): array
    {
        return BusinessStateDefinition::query()
            ->where('entity_type', $entityType)
            ->where('is_initial', true)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->all();
    }

    public function defaultWorkflow(string $entityType): ?WorkflowDefinition
    {
        return WorkflowDefinition::query()
            ->where('entity_type', $entityType)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->first();
    }

    /**
     * @return array<int, WorkflowTransition>
     */
    public function availableTransitions(string $entityType, string $fromCode, ?User $user = null, ?string $workflowCode = null): array
    {
        $workflow = $this->workflowFor($entityType, $workflowCode);

        if ($workflow) {
            return WorkflowTransition::query()
                ->where('workflow_definition_id', $workflow->id)
                ->where('from_state_code', $fromCode)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get()
                ->filter(fn (WorkflowTransition $transition) => blank($transition->required_permission) || $user?->can($transition->required_permission))
                ->values()
                ->all();
        }

        $from = BusinessStateDefinition::query()
            ->where('entity_type', $entityType)
            ->where('code', $fromCode)
            ->where('is_active', true)
            ->first();

        return collect($from?->allowed_transitions ?? [])
            ->map(fn (string $toCode) => new WorkflowTransition([
                'from_state_code' => $fromCode,
                'to_state_code' => $toCode,
                'label' => $toCode,
                'is_active' => true,
            ]))
            ->values()
            ->all();
    }

    private function workflowFor(string $entityType, ?string $workflowCode = null): ?WorkflowDefinition
    {
        if (filled($workflowCode)) {
            return WorkflowDefinition::query()
                ->where('entity_type', $entityType)
                ->where('code', $workflowCode)
                ->where('is_active', true)
                ->first();
        }

        return $this->defaultWorkflow($entityType);
    }

    /**
     * @return array{allowed:bool, reason:?string, transition:?WorkflowTransition, actions:array<int|string, mixed>, validations:array<int|string, mixed>}
     */
    private function result(bool $allowed, ?string $reason = null, ?WorkflowTransition $transition = null): array
    {
        return [
            'allowed' => $allowed,
            'reason' => $reason,
            'transition' => $transition,
            'actions' => $transition?->actions ?? [],
            'validations' => $transition?->validations ?? [],
        ];
    }
}
