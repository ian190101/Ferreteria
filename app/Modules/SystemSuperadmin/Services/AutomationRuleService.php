<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Modules\SystemSuperadmin\Models\AutomationRun;
use App\Modules\SystemSuperadmin\Models\AutomationRule;
use Illuminate\Support\Facades\DB;

class AutomationRuleService
{
    /**
     * @return array<int, AutomationRule>
     */
    public function matchingRules(string $entityType, string $trigger, array $context = []): array
    {
        if (! $this->enabled()) {
            return [];
        }

        return AutomationRule::query()
            ->where('entity_type', $entityType)
            ->where('trigger', $trigger)
            ->where('is_active', true)
            ->get()
            ->filter(fn (AutomationRule $rule) => $this->conditionsMatch($rule->conditions ?? [], $context) && $this->cooldownAllows($rule))
            ->values()
            ->all();
    }

    public function markExecuted(AutomationRule $rule): void
    {
        $rule->forceFill(['last_run_at' => now()])->save();
    }

    /**
     * @return array<int, AutomationRun>
     */
    public function execute(string $entityType, string $trigger, array $context = [], ?int $entityId = null): array
    {
        $rules = $this->matchingRules($entityType, $trigger, $context);

        return collect($rules)
            ->map(fn (AutomationRule $rule) => $this->executeRule($rule, $entityType, $trigger, $context, $entityId))
            ->values()
            ->all();
    }

    public function executeRule(AutomationRule $rule, string $entityType, string $trigger, array $context = [], ?int $entityId = null): AutomationRun
    {
        return DB::transaction(function () use ($rule, $entityType, $trigger, $context, $entityId): AutomationRun {
            $rule = $rule->lockForUpdate()->findOrFail($rule->id);

            if (! $rule->is_active || ! $this->cooldownAllows($rule)) {
                $run = AutomationRun::query()->create([
                    'automation_rule_id' => $rule->id,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'trigger' => $trigger,
                    'status' => AutomationRun::STATUS_SKIPPED,
                    'context' => $context,
                    'actions' => $rule->actions ?? [],
                    'result_message' => 'La regla no se ejecuto por inactividad o cooldown.',
                    'executed_at' => now(),
                ]);

                app(OperationalTraceService::class)->record(
                    $entityType,
                    $entityId,
                    'automation_skipped',
                    null,
                    (int) ($context['branch_id'] ?? 0) ?: null,
                    [
                        'automation_run_id' => $run->id,
                        'automation_rule_code' => $rule->code,
                        'trigger' => $trigger,
                        'message' => $run->result_message,
                    ],
                    $context,
                    'automation_engine',
                    AutomationRun::STATUS_SKIPPED,
                );

                return $run;
            }

            $run = AutomationRun::query()->create([
                'automation_rule_id' => $rule->id,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'trigger' => $trigger,
                'status' => AutomationRun::STATUS_COMPLETED,
                'context' => $context,
                'actions' => $rule->actions ?? [],
                'result_message' => 'Acciones registradas para ejecucion controlada.',
                'executed_at' => now(),
            ]);

            $this->markExecuted($rule);

            app(OperationalTraceService::class)->record(
                $entityType,
                $entityId,
                'automation_executed',
                null,
                (int) ($context['branch_id'] ?? 0) ?: null,
                [
                    'automation_run_id' => $run->id,
                    'automation_rule_code' => $rule->code,
                    'trigger' => $trigger,
                    'message' => $run->result_message,
                ],
                [
                    'conditions' => $rule->conditions ?? [],
                    'actions' => $rule->actions ?? [],
                    'context' => $context,
                ],
                'automation_engine',
                AutomationRun::STATUS_COMPLETED,
            );

            return $run;
        });
    }

    private function enabled(): bool
    {
        $payload = ActiveBusinessProfile::payload();

        return (bool) ($payload['feature_flags']['automation_engine'] ?? false)
            && (bool) ($payload['automations']['enabled'] ?? false);
    }

    private function cooldownAllows(AutomationRule $rule): bool
    {
        if (! $rule->last_run_at || $rule->cooldown_minutes <= 0) {
            return true;
        }

        return $rule->last_run_at->addMinutes($rule->cooldown_minutes)->isPast();
    }

    /**
     * @param array<string, mixed> $conditions
     * @param array<string, mixed> $context
     */
    private function conditionsMatch(array $conditions, array $context): bool
    {
        foreach ($conditions as $key => $expected) {
            if (($context[$key] ?? null) !== $expected) {
                return false;
            }
        }

        return true;
    }
}
