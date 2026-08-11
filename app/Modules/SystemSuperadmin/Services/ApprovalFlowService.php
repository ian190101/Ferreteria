<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Models\User;
use App\Modules\SystemSuperadmin\Models\ApprovalFlowRule;
use App\Modules\SystemSuperadmin\Models\ApprovalRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalFlowService
{
    public function requiredRule(string $entityType, string $action, array $context = []): ?ApprovalFlowRule
    {
        if (! $this->enabled()) {
            return null;
        }

        return ApprovalFlowRule::query()
            ->where('entity_type', $entityType)
            ->where('action', $action)
            ->where('is_active', true)
            ->orderByDesc('blocks_until_approved')
            ->get()
            ->first(fn (ApprovalFlowRule $rule) => $this->conditionsMatch($rule->conditions ?? [], $context));
    }

    public function createRequest(ApprovalFlowRule $rule, string $entityType, int $entityId, string $action, User $requester, ?string $reason = null, array $payload = []): ApprovalRequest
    {
        return DB::transaction(function () use ($rule, $entityType, $entityId, $action, $requester, $reason, $payload): ApprovalRequest {
            $approvalRequest = ApprovalRequest::query()->create([
                'approval_flow_rule_id' => $rule->id,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'action' => $action,
                'status' => ApprovalRequest::STATUS_PENDING,
                'requested_by' => $requester->id,
                'expires_at' => $rule->expires_after_minutes ? now()->addMinutes($rule->expires_after_minutes) : null,
                'reason' => $reason,
                'payload' => $payload,
                'approvals' => [],
            ]);

            app(OperationalTraceService::class)->record(
                $entityType,
                $entityId,
                'approval_requested',
                $requester,
                null,
                [
                    'approval_request_id' => $approvalRequest->id,
                    'approval_rule_code' => $rule->code,
                    'action' => $action,
                    'reason' => $reason,
                ],
                ['payload' => $payload],
                'approval_engine',
                ApprovalRequest::STATUS_PENDING,
            );

            return $approvalRequest;
        });
    }

    public function approve(ApprovalRequest $request, User $approver, ?string $note = null): ApprovalRequest
    {
        $request = $this->expireIfNeeded($request);
        $this->ensurePending($request);

        return DB::transaction(function () use ($request, $approver, $note): ApprovalRequest {
            $request = $request->lockForUpdate()->findOrFail($request->id);
            $this->ensurePending($request);

            $approvals = $request->approvals ?? [];
            $alreadyApproved = collect($approvals)->contains(fn (array $approval) => (int) ($approval['user_id'] ?? 0) === (int) $approver->id);

            if ($alreadyApproved) {
                throw ValidationException::withMessages([
                    'note' => 'Este usuario ya registro una aprobacion para esta solicitud.',
                ]);
            }

            $approvals[] = [
                'user_id' => $approver->id,
                'name' => $approver->name,
                'approved_at' => now()->toIso8601String(),
                'note' => $note,
            ];

            $minApprovals = max((int) ($request->rule?->min_approvals ?? 1), 1);
            $status = count($approvals) >= $minApprovals
                ? ApprovalRequest::STATUS_APPROVED
                : ApprovalRequest::STATUS_PENDING;

            $request->update([
                'approvals' => $approvals,
                'status' => $status,
                'resolved_by' => $status === ApprovalRequest::STATUS_APPROVED ? $approver->id : null,
                'resolved_at' => $status === ApprovalRequest::STATUS_APPROVED ? now() : null,
                'resolution_note' => $status === ApprovalRequest::STATUS_APPROVED ? $note : $request->resolution_note,
            ]);

            $request = $request->refresh();

            app(OperationalTraceService::class)->record(
                $request->entity_type,
                $request->entity_id,
                $status === ApprovalRequest::STATUS_APPROVED ? 'approval_approved' : 'approval_partial_approved',
                $approver,
                null,
                [
                    'approval_request_id' => $request->id,
                    'approval_rule_code' => $request->rule?->code,
                    'action' => $request->action,
                    'note' => $note,
                    'approvals_count' => count($approvals),
                    'min_approvals' => $minApprovals,
                ],
                ['approvals' => $approvals],
                'approval_engine',
                $status,
            );

            return $request;
        });
    }

    public function reject(ApprovalRequest $request, User $approver, string $note): ApprovalRequest
    {
        $request = $this->expireIfNeeded($request);
        $this->ensurePending($request);

        return DB::transaction(function () use ($request, $approver, $note): ApprovalRequest {
            $request = $request->lockForUpdate()->findOrFail($request->id);
            $this->ensurePending($request);

            $request->update([
                'status' => ApprovalRequest::STATUS_REJECTED,
                'resolved_by' => $approver->id,
                'resolved_at' => now(),
                'resolution_note' => $note,
            ]);

            $request = $request->refresh();

            app(OperationalTraceService::class)->record(
                $request->entity_type,
                $request->entity_id,
                'approval_rejected',
                $approver,
                null,
                [
                    'approval_request_id' => $request->id,
                    'approval_rule_code' => $request->rule?->code,
                    'action' => $request->action,
                    'note' => $note,
                ],
                [],
                'approval_engine',
                ApprovalRequest::STATUS_REJECTED,
            );

            return $request;
        });
    }

    public function expireIfNeeded(ApprovalRequest $request): ApprovalRequest
    {
        if (
            $request->status === ApprovalRequest::STATUS_PENDING
            && $request->expires_at
            && $request->expires_at->isPast()
        ) {
            $request->update([
                'status' => ApprovalRequest::STATUS_EXPIRED,
                'resolved_at' => now(),
                'resolution_note' => 'La solicitud vencio antes de recibir aprobacion.',
            ]);
        }

        return $request->refresh();
    }

    private function enabled(): bool
    {
        $payload = ActiveBusinessProfile::payload();

        return (bool) ($payload['feature_flags']['approval_engine'] ?? false)
            && (bool) ($payload['approvals']['enabled'] ?? false);
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

    private function ensurePending(ApprovalRequest $request): void
    {
        if ($request->status !== ApprovalRequest::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'note' => 'Solo se pueden resolver solicitudes pendientes.',
            ]);
        }
    }
}
