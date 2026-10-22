<?php

declare(strict_types=1);

namespace App\Modules\Purchases\Services;

use App\Models\User;
use App\Modules\Purchases\Models\Purchase;
use App\Support\BranchAccess;

final class PurchaseVoidAuthorization
{
    public function allows(User $user, Purchase $purchase): bool
    {
        if (! $user->can('purchases.manage') || ! BranchAccess::canAccess($user, (int) $purchase->branch_id)) {
            return false;
        }

        if ($user->can('payments.manage')) {
            return true;
        }

        $hasPayments = $purchase->getAttribute('payments_exists') ?? $purchase->payments()->exists();

        return ! $hasPayments && (float) $purchase->paid_amount <= 0;
    }

    public function authorize(User $user, Purchase $purchase): void
    {
        abort_unless($this->allows($user, $purchase), 403, 'No tienes permisos para anular esta compra y sus pagos.');
    }
}
