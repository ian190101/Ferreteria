<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\ProductPriceRule;
use App\Modules\Sales\Models\Sale;
use App\Modules\SystemSuperadmin\Services\ActiveBusinessProfile;

class CommercialPolicy
{
    public function pricePolicy(): string
    {
        return $this->salesValue('price_policy', 'base_price');
    }

    public function discountPolicy(): string
    {
        return $this->salesValue('discount_policy', 'permission');
    }

    public function maxDiscountPercent(): float
    {
        return max((float) (ActiveBusinessProfile::payload()['sales']['max_discount_percent'] ?? 0), 0);
    }

    public function creditLimitPolicy(): string
    {
        return $this->salesValue('credit_limit_policy', 'disabled');
    }

    public function defaultCreditLimit(): float
    {
        return max((float) (ActiveBusinessProfile::payload()['sales']['default_credit_limit'] ?? 0), 0);
    }

    public function negativeStockPolicy(): string
    {
        return $this->salesValue('negative_stock_policy', 'never');
    }

    public function canSellNegativeStock(User $user, ?string $categoryName = null): bool
    {
        $sales = ActiveBusinessProfile::payload()['sales'] ?? [];

        return match ($this->negativeStockPolicy()) {
            'global' => (bool) ($sales['allow_negative_stock'] ?? false),
            'role' => $this->userHasListedRole($user, $sales['negative_stock_roles'] ?? []),
            'category' => filled($categoryName) && in_array(strtolower($categoryName), $this->normalizedList($sales['negative_stock_categories'] ?? []), true),
            default => false,
        };
    }

    public function canApplyDiscount(User $user): bool
    {
        $sales = ActiveBusinessProfile::payload()['sales'] ?? [];

        return match ($this->discountPolicy()) {
            'never' => false,
            'permission' => $user->can('sales.prices.override'),
            'role_limit' => $this->userHasListedRole($user, $sales['discount_roles'] ?? []),
            'always_with_limit' => true,
            default => $user->can('sales.prices.override'),
        };
    }

    public function priceFor(Product $product, ?int $branchId = null, ?int $customerId = null): float
    {
        $policy = $this->pricePolicy();

        if (in_array($policy, ['customer_price', 'mixed'], true) && $customerId) {
            $price = $this->rulePrice($product->id, null, $customerId);

            if ($price !== null) {
                return $price;
            }
        }

        if (in_array($policy, ['branch_price', 'mixed'], true) && $branchId) {
            $price = $this->rulePrice($product->id, $branchId, null);

            if ($price !== null) {
                return $price;
            }
        }

        return (float) $product->sale_price;
    }

    public function assertCreditAllowed(?Customer $customer, float $newBalance): void
    {
        if (! $customer || $newBalance <= 0 || $this->creditLimitPolicy() === 'disabled') {
            return;
        }

        $limit = (float) ($customer->credit_limit ?? 0);

        if ($limit <= 0) {
            $limit = $this->defaultCreditLimit();
        }

        if ($limit <= 0) {
            return;
        }

        $currentBalance = (float) Sale::query()
            ->where('customer_id', $customer->id)
            ->where('balance_due', '>', 0)
            ->sum('balance_due');

        if (($currentBalance + $newBalance) > $limit && $this->creditLimitPolicy() === 'block') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'customer_id' => 'El cliente supera el limite de credito configurado para este negocio.',
            ]);
        }
    }

    public function discountExceedsLimit(float $lineGross, float $discountAmount): bool
    {
        $limit = $this->maxDiscountPercent();

        if ($limit <= 0) {
            return $discountAmount > 0;
        }

        if ($lineGross <= 0) {
            return $discountAmount > 0;
        }

        return (($discountAmount / $lineGross) * 100) > $limit;
    }

    public function summary(): array
    {
        return [
            'pricePolicy' => $this->pricePolicy(),
            'discountPolicy' => $this->discountPolicy(),
            'maxDiscountPercent' => $this->maxDiscountPercent(),
            'creditLimitPolicy' => $this->creditLimitPolicy(),
            'defaultCreditLimit' => $this->defaultCreditLimit(),
            'negativeStockPolicy' => $this->negativeStockPolicy(),
            'negativeStockRoles' => ActiveBusinessProfile::payload()['sales']['negative_stock_roles'] ?? [],
            'negativeStockCategories' => ActiveBusinessProfile::payload()['sales']['negative_stock_categories'] ?? [],
            'discountRoles' => ActiveBusinessProfile::payload()['sales']['discount_roles'] ?? [],
        ];
    }

    private function salesValue(string $key, string $fallback): string
    {
        $value = ActiveBusinessProfile::payload()['sales'][$key] ?? null;

        return filled($value) ? (string) $value : $fallback;
    }

    private function rulePrice(int $productId, ?int $branchId, ?int $customerId): ?float
    {
        $rule = ProductPriceRule::query()
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId), fn ($query) => $query->whereNull('branch_id'))
            ->when($customerId, fn ($query) => $query->where('customer_id', $customerId), fn ($query) => $query->whereNull('customer_id'))
            ->latest('id')
            ->first(['price']);

        return $rule ? (float) $rule->price : null;
    }

    private function userHasListedRole(User $user, array $roles): bool
    {
        $allowed = $this->normalizedList($roles);

        if ($allowed === []) {
            return false;
        }

        return $user->roles
            ->pluck('name')
            ->map(fn (string $role) => strtolower($role))
            ->intersect($allowed)
            ->isNotEmpty();
    }

    private function normalizedList(array $values): array
    {
        return collect($values)
            ->map(fn ($value) => strtolower(trim((string) $value)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
