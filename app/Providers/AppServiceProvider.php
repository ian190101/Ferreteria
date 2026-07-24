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
use App\Modules\Sales\Events\SaleNoteIssued;
use App\Modules\Sales\Listeners\ProcessSaleNoteIssued;
use App\Support\AuthSessionCache;
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
        $models = [
            BankAccount::class,
            BankTransaction::class,
            Branch::class,
            BranchSetting::class,
            CashRegisterSession::class,
            Customer::class,
            CustomerType::class,
            Expense::class,
            ExpenseCategory::class,
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
            CreditNote::class,
            PaymentMethod::class,
            PaymentPromise::class,
            PurchasePayment::class,
            SalePayment::class,
            ProductionOrder::class,
            Purchase::class,
            PurchaseOrder::class,
            PurchaseOrderReceipt::class,
            Supplier::class,
            AdvanceOption::class,
            Currency::class,
            DeliveryDriver::class,
            DeliveryNote::class,
            DeliveryTruck::class,
            DocumentSequence::class,
            ReceiptTemplate::class,
            Sale::class,
            SaleReturn::class,
            SaleType::class,
        ];

        foreach ($models as $model) {
            $model::saved(fn () => $this->invalidateRuntimeCaches());
            $model::deleted(fn () => $this->invalidateRuntimeCaches());
        }
    }

    private function invalidateRuntimeCaches(): void
    {
        SystemCacheInvalidator::bumpOperational();
        UiCatalogCache::forgetProductCatalogs();
        UiCatalogCache::forgetFinancialCatalogs();
        UiCatalogCache::forgetSalesCatalogs();
    }
}
