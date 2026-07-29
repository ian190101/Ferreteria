<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Banks\Models\BankAccount;
use App\Modules\Banks\Models\BankTransaction;
use App\Modules\Branches\Models\Branch;
use App\Modules\Branches\Models\BranchSetting;
use App\Modules\Cash\Models\CashRegisterSession;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerType;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Inventory\Models\InventoryTransfer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\ProductCategoryAttribute;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Thickness;
use App\Modules\Payments\Models\CreditNote;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Models\PaymentPromise;
use App\Modules\Payments\Models\PurchasePayment;
use App\Modules\Payments\Models\SalePayment;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Purchases\Models\Purchase;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Models\PurchaseOrderReceipt;
use App\Modules\Purchases\Models\Supplier;
use App\Modules\Sales\Models\AdvanceOption;
use App\Modules\Sales\Models\Currency;
use App\Modules\Sales\Models\DeliveryDriver;
use App\Modules\Sales\Models\DeliveryNote;
use App\Modules\Sales\Models\DeliveryTruck;
use App\Modules\Sales\Models\DocumentSequence;
use App\Modules\Sales\Models\ReceiptTemplate;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Sales\Models\SaleType;
use App\Modules\Settings\Models\SystemSetting;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\Sales\Events\SaleNoteIssued;
use App\Modules\Sales\Listeners\ProcessSaleNoteIssued;
use App\Support\AuthSessionCache;
use App\Support\DecimalPrecision;
use App\Support\SystemCacheInvalidator;
use App\Support\UiCatalogCache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(function (User $user, string $ability) {
            return AuthSessionCache::isSuperAdministrator($user) ? true : null;
        });

        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceRootUrl((string) config('app.url'));
            URL::forceScheme('https');
        }

        // Evita enlaces http absolutos cuando la app se sirve detras de Cloudflare Tunnel.
        Vite::createAssetPathsUsing(fn (string $path) => '/'.$path);
        Vite::prefetch(concurrency: 3);

        $this->registerDomainEvents();
        $this->registerCacheInvalidationHooks();
    }

    private function registerDomainEvents(): void
    {
        Event::listen(SaleNoteIssued::class, ProcessSaleNoteIssued::class);
    }

    private function registerCacheInvalidationHooks(): void
    {
        $models = collect([
            ...$this->inventoryCacheModels(),
            ...$this->financialCacheModels(),
            ...$this->salesCacheModels(),
            Branch::class,
            BranchSetting::class,
            BusinessProfile::class,
            Supplier::class,
            SystemSetting::class,
        ])->unique()->values()->all();

        foreach ($models as $model) {
            $model::saved(fn () => $this->invalidateRuntimeCaches($model));
            $model::deleted(fn () => $this->invalidateRuntimeCaches($model));
        }
    }

    private function invalidateRuntimeCaches(string $model): void
    {
        SystemCacheInvalidator::bumpOperational();

        if (in_array($model, $this->inventoryCacheModels(), true)) {
            UiCatalogCache::forgetProductCatalogs();
        }

        if (in_array($model, $this->financialCacheModels(), true)) {
            UiCatalogCache::forgetFinancialCatalogs();
        }

        if (in_array($model, $this->salesCacheModels(), true)) {
            UiCatalogCache::forgetSalesCatalogs();
        }

        if ($model === SystemSetting::class) {
            DecimalPrecision::forget();
        }
    }

    private function inventoryCacheModels(): array
    {
        return [
            InventoryAdjustment::class,
            InventoryMovement::class,
            InventoryReservation::class,
            InventoryTransfer::class,
            Product::class,
            ProductBranchStock::class,
            ProductCategory::class,
            ProductCategoryAttribute::class,
            ProductCoil::class,
            ProductUnit::class,
            Thickness::class,
            ProductionOrder::class,
            Purchase::class,
            PurchaseOrder::class,
            PurchaseOrderReceipt::class,
            Sale::class,
            SaleReturn::class,
        ];
    }

    private function financialCacheModels(): array
    {
        return [
            BankAccount::class,
            BankTransaction::class,
            CashRegisterSession::class,
            CreditNote::class,
            Expense::class,
            ExpenseCategory::class,
            PaymentMethod::class,
            PurchasePayment::class,
            SalePayment::class,
        ];
    }

    private function salesCacheModels(): array
    {
        return [
            AdvanceOption::class,
            Currency::class,
            Customer::class,
            CustomerType::class,
            DeliveryDriver::class,
            DeliveryNote::class,
            DeliveryTruck::class,
            DocumentSequence::class,
            PaymentPromise::class,
            ReceiptTemplate::class,
            Sale::class,
            SaleReturn::class,
            SaleType::class,
        ];
    }
}
