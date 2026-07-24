<?php

namespace App\Modules\HumanResources\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Banks\Services\BankReconciliationService;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Finance\Services\FinancialLedgerService;
use App\Modules\HumanResources\Models\SalaryPayment;
use App\Modules\HumanResources\Models\Worker;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Sales\Services\SalesDocumentPolicy;
use App\Modules\SystemSuperadmin\Services\ActiveBusinessProfile;
use App\Support\BranchAccess;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SalaryPaymentController extends Controller
{
    public function index(Request $request): Response
    {
        $documentPolicy = app(SalesDocumentPolicy::class);

        return Inertia::render('HumanResources/Payroll/Index', [
            'payments' => SalaryPayment::query()
                ->with(['worker:id,name,position', 'branch:id,name', 'paymentMethod:id,name', 'user:id,name'])
                ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
                ->latest('paid_at')
                ->paginate($request->integer('per_page', 15))
                ->withQueryString(),
            'workers' => Worker::query()
                ->where('is_active', true)
                ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
                ->orderBy('name')
                ->get(['id', 'branch_id', 'name', 'position', 'salary_amount']),
            'branches' => UiCatalogCache::activeBranchesForUser($request->user(), ['id', 'name']),
            'paymentMethods' => UiCatalogCache::activePaymentMethods(['id', 'name', 'code'])
                ->filter(fn ($method) => $documentPolicy->isPaymentMethodAllowed($method->code, 'payroll'))
                ->values(),
            'filters' => $request->only(['per_page']),
        ]);
    }

    public function store(Request $request, BankReconciliationService $banks, FinancialLedgerService $ledger): RedirectResponse
    {
        $data = $request->validate([
            'worker_id' => ['required', 'integer', 'exists:workers,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'period_from' => ['nullable', 'date'],
            'period_to' => ['nullable', 'date', 'after_or_equal:period_from'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999999.99'],
            'reference' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        abort_unless(BranchAccess::canAccess($request->user(), (int) $data['branch_id']), 403);
        $method = filled($data['payment_method_id'] ?? null)
            ? PaymentMethod::query()->find($data['payment_method_id'])
            : null;

        if ($method && ! $method->is_active) {
            throw ValidationException::withMessages(['payment_method_id' => 'El metodo de pago no esta activo.']);
        }

        if ($method && ! app(SalesDocumentPolicy::class)->isPaymentMethodAllowed($method->code, 'payroll')) {
            throw ValidationException::withMessages(['payment_method_id' => 'El perfil de negocio actual no permite usar este metodo de pago en pago de sueldos.']);
        }

        if ($method?->requires_reference && blank($data['reference'] ?? null)) {
            throw ValidationException::withMessages(['reference' => 'La referencia es obligatoria para este metodo de pago.']);
        }

        DB::transaction(function () use ($data, $request, $banks) {
            $worker = Worker::query()->findOrFail($data['worker_id']);
            abort_unless(BranchAccess::canAccess($request->user(), (int) $worker->branch_id), 403);

            if ((int) $worker->branch_id !== (int) $data['branch_id']) {
                throw ValidationException::withMessages([
                    'branch_id' => 'La sucursal del pago debe coincidir con la sucursal asignada al trabajador.',
                ]);
            }

            $expense = null;

            if ($this->shouldIntegrateWithExpenses()) {
                $category = ExpenseCategory::query()->firstOrCreate(
                    ['code' => ExpenseCategory::SALARY_PAYROLL_CODE],
                    ['name' => ExpenseCategory::SALARY_PAYROLL_NAME, 'is_active' => true],
                );

                $expense = Expense::query()->create([
                    'branch_id' => $data['branch_id'],
                    'expense_category_id' => $category->id,
                    'payment_method_id' => $data['payment_method_id'] ?? null,
                    'user_id' => $request->user()->id,
                    'spent_at' => now(),
                    'description' => 'Pago de sueldo: '.$worker->name,
                    'amount' => $data['amount'],
                    'reference' => $data['reference'] ?? null,
                    'status' => Expense::STATUS_REGISTERED,
                    'notes' => $data['notes'] ?? null,
                ]);

                $banks->recordExpense($expense);
            }

            SalaryPayment::query()->create([
                ...$data,
                'expense_id' => $expense?->id,
                'user_id' => $request->user()->id,
                'paid_at' => now(),
                'status' => SalaryPayment::STATUS_PAID,
            ]);
        });

        $ledger->bumpFinancialCaches();

        return back()->with('success', 'Pago de sueldo registrado correctamente.');
    }

    public function void(Request $request, SalaryPayment $payment, BankReconciliationService $banks, FinancialLedgerService $ledger): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        abort_unless(BranchAccess::canAccess($request->user(), (int) $payment->branch_id), 403);

        DB::transaction(function () use ($request, $payment, $banks, $data) {
            $payment = SalaryPayment::query()
                ->with('expense')
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($payment->status === SalaryPayment::STATUS_VOID) {
                return;
            }

            $voidReason = 'Pago de sueldo anulado por '.$request->user()->name.': '.($data['reason'] ?? 'Sin motivo registrado');
            $notes = trim(implode("\n", array_filter([$payment->notes, $voidReason])));

            if ($payment->expense) {
                $banks->voidForSource($payment->expense, $voidReason);
                $payment->expense->update([
                    'status' => Expense::STATUS_VOID,
                    'notes' => trim(implode("\n", array_filter([$payment->expense->notes, $voidReason]))),
                ]);
            }

            $payment->update([
                'status' => SalaryPayment::STATUS_VOID,
                'notes' => $notes,
            ]);
        });

        $ledger->bumpFinancialCaches();

        return back()->with('success', 'Pago de sueldo anulado correctamente.');
    }

    private function shouldIntegrateWithExpenses(): bool
    {
        return (bool) (ActiveBusinessProfile::payload()['human_resources']['salary_expense_integration'] ?? true);
    }

}
