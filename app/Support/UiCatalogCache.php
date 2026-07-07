<?php

namespace App\Support;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Customers\Models\Customer;
use App\Modules\Banks\Models\BankAccount;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Thickness;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Purchases\Models\Supplier;
use App\Modules\Sales\Models\AdvanceOption;
use App\Modules\Sales\Models\Currency;
use App\Modules\Sales\Models\SaleType;
use Illuminate\Support\Facades\Cache;

class UiCatalogCache
{
    private const CATALOG_TTL_MINUTES = 10;

    public static function activeBranches(array $columns = ['id', 'name'])
    {
        return self::remember('branches:'.implode(',', $columns), fn () => Branch::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get($columns));
    }

    public static function activeBranchesForUser(User $user, array $columns = ['id', 'name'])
    {
        if ($user->isSuperAdministrator()) {
            return self::activeBranches($columns);
        }

        return Branch::query()
            ->where('is_active', true)
            ->whereIn('id', $user->accessibleBranchIds() ?: [-1])
            ->orderBy('name')
            ->get($columns);
    }

    public static function activeProducts(array $columns = ['id', 'name', 'sku', 'inventory_tracking_mode'])
    {
        return self::remember('products:'.implode(',', $columns), fn () => Product::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get($columns));
    }

    public static function activeProductsWithThickness()
    {
        return self::remember('products-with-thickness', fn () => Product::query()
            ->with([
                'thickness:id,name,kg_to_meter_factor,kg_per_meter',
                'unit:id,name,symbol,kind',
                'productCategory.attributes' => fn ($query) => $query
                    ->where('is_active', true)
                    ->with('unit:id,name,symbol')
                    ->orderBy('sort_order')
                    ->orderBy('name'),
            ])
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'thickness_id', 'product_category_id', 'product_unit_id', 'name', 'sku', 'inventory_tracking_mode', 'base_unit', 'attributes', 'purchase_price', 'sale_price']));
    }

    public static function activeCoilProducts()
    {
        return self::remember('coil-products', fn () => Product::query()
            ->where('is_active', true)
            ->where('inventory_tracking_mode', Product::TRACKING_COIL)
            ->orderBy('name')
            ->get(['id', 'name', 'sku']));
    }

    public static function activeThicknesses()
    {
        return self::remember('thicknesses', fn () => Thickness::query()
            ->where('is_active', true)
            ->orderBy('millimeters')
            ->get(['id', 'name', 'millimeters', 'kg_to_meter_factor', 'kg_per_meter']));
    }

    public static function productCategories()
    {
        return self::remember('product-categories', fn () => ProductCategory::query()
            ->with(['defaultUnit:id,name,symbol', 'attributes' => fn ($query) => $query
                ->where('is_active', true)
                ->with('unit:id,name,symbol')
                ->orderBy('sort_order')
                ->orderBy('name')])
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'default_unit_id', 'name', 'default_tracking_mode', 'requires_thickness']));
    }

    public static function productUnits()
    {
        return self::remember('product-units', fn () => ProductUnit::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'symbol', 'kind']));
    }

    public static function availableCoils(int $limit = 500)
    {
        return Cache::remember("ui-catalog:available-coils:{$limit}", now()->addSeconds(30), fn () => ProductCoil::query()
            ->where('status', 'available')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'branch_id', 'product_id', 'barcode', 'lot_number', 'available_meters']));
    }

    public static function activeSuppliers()
    {
        return self::remember('suppliers', fn () => Supplier::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']));
    }

    public static function activeBankAccounts()
    {
        return self::remember('bank-accounts', fn () => BankAccount::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'account_number', 'currency_code', 'current_balance']));
    }

    public static function activeExpenseCategories()
    {
        return self::remember('expense-categories', fn () => ExpenseCategory::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']));
    }

    public static function activePaymentMethods(array $columns = ['id', 'name', 'requires_reference'])
    {
        return self::remember('payment-methods:'.implode(',', $columns), fn () => PaymentMethod::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get($columns));
    }

    public static function saleTypes()
    {
        return self::remember('sale-types', fn () => SaleType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']));
    }

    public static function currencies()
    {
        return self::remember('currencies', fn () => Currency::query()
            ->where('is_active', true)
            ->orderByDesc('is_base')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'symbol', 'exchange_rate_to_bob', 'is_base']));
    }

    public static function advanceOptions()
    {
        return self::remember('advance-options', fn () => AdvanceOption::query()
            ->where('is_active', true)
            ->orderBy('percentage')
            ->get(['id', 'name', 'percentage']));
    }

    public static function recentCustomers(int $limit = 100)
    {
        return Cache::remember("ui-catalog:recent-customers:{$limit}", now()->addMinutes(5), fn () => Customer::query()
            ->where('is_active', true)
            ->with('type:id,name')
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'customer_type_id', 'name', 'document_number', 'phone']));
    }

    public static function forgetProductCatalogs(): void
    {
        foreach ([
            'products:id,name,sku',
            'products:id,name,sku,inventory_tracking_mode',
            'products-with-thickness',
            'coil-products',
            'product-categories',
            'product-units',
            'thicknesses',
        ] as $key) {
            Cache::forget("ui-catalog:{$key}");
        }
    }

    public static function forgetFinancialCatalogs(): void
    {
        foreach (['bank-accounts', 'expense-categories', 'payment-methods:id,name', 'payment-methods:id,name,requires_reference'] as $key) {
            Cache::forget("ui-catalog:{$key}");
        }
    }

    public static function forgetSalesCatalogs(): void
    {
        foreach (['sale-types', 'currencies', 'advance-options'] as $key) {
            Cache::forget("ui-catalog:{$key}");
        }
    }

    private static function remember(string $key, callable $callback)
    {
        return Cache::remember("ui-catalog:{$key}", now()->addMinutes(self::CATALOG_TTL_MINUTES), $callback);
    }
}
