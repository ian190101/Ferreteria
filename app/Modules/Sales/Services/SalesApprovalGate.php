<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\Sales\Models\Sale;
use App\Modules\SystemSuperadmin\Services\ApprovalFlowService;
use Illuminate\Validation\ValidationException;

class SalesApprovalGate
{
    public function __construct(private readonly ApprovalFlowService $approvals)
    {
    }

    public function requireForHighDiscount(User $user, int $branchId, float $discountPercent, array $payload = []): void
    {
        $rule = $this->approvals->requiredRule('sale', 'high_discount', [
            'branch_id' => $branchId,
            'discount_percent' => $discountPercent,
        ]) ?? $this->approvals->requiredRule('sale', 'high_discount', []);

        if (! $rule) {
            return;
        }

        $this->approvals->createRequest(
            rule: $rule,
            entityType: 'sale',
            entityId: 0,
            action: 'high_discount',
            requester: $user,
            reason: 'Descuento alto solicitado desde venta.',
            payload: [
                ...$payload,
                'branch_id' => $branchId,
                'discount_percent' => $discountPercent,
            ],
        );

        throw ValidationException::withMessages([
            'items' => 'El descuento requiere aprobacion. Se creo una solicitud pendiente para sistemasuperadmin/gerencia.',
        ]);
    }

    public function requireForVoid(Sale $sale, User $user, string $reason): void
    {
        $rule = $this->approvals->requiredRule('sale', 'void_sale', [
            'branch_id' => (int) $sale->branch_id,
            'status' => (string) $sale->status,
            'document_type' => (string) $sale->document_type,
        ]) ?? $this->approvals->requiredRule('sale', 'void_sale', []);

        if (! $rule) {
            return;
        }

        $this->approvals->createRequest(
            rule: $rule,
            entityType: 'sale',
            entityId: (int) $sale->id,
            action: 'void_sale',
            requester: $user,
            reason: $reason,
            payload: [
                'branch_id' => (int) $sale->branch_id,
                'receipt_number' => $sale->receipt_number,
                'total' => (float) $sale->total,
                'status' => $sale->status,
                'document_type' => $sale->document_type,
            ],
        );

        throw ValidationException::withMessages([
            'reason' => 'La anulacion requiere aprobacion. Se creo una solicitud pendiente y el documento no fue anulado.',
        ]);
    }
}
