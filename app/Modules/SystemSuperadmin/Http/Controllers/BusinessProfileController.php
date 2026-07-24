<?php

namespace App\Modules\SystemSuperadmin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Models\BusinessProfileDraft;
use App\Modules\SystemSuperadmin\Models\BusinessProfilePreset;
use App\Modules\SystemSuperadmin\Models\BusinessProfileSandboxSession;
use App\Modules\SystemSuperadmin\Models\BusinessProfileVersion;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use App\Modules\SystemSuperadmin\Services\BusinessProfileSandboxService;
use App\Support\SystemCacheInvalidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BusinessProfileController extends Controller
{
    public function index(Request $request, BusinessProfileSandboxService $sandbox): Response
    {
        $activeProfile = BusinessProfile::query()
            ->where('status', 'active')
            ->latest('applied_at')
            ->first();
        $activeProfile?->setAttribute('configuration', BusinessProfileConfiguration::normalized($activeProfile->configuration ?? []));

        $drafts = BusinessProfileDraft::query()
            ->with(['creator:id,name', 'updater:id,name'])
            ->latest('updated_at')
            ->get()
            ->each(fn (BusinessProfileDraft $draft) => $draft->setAttribute('configuration', BusinessProfileConfiguration::normalized($draft->configuration ?? [])));

        $versions = BusinessProfileVersion::query()
            ->with('appliedBy:id,name')
            ->latest('version_number')
            ->limit(10)
            ->get()
            ->each(fn (BusinessProfileVersion $version) => $version->setAttribute('configuration', BusinessProfileConfiguration::normalized($version->configuration ?? [])));
        $presets = BusinessProfilePreset::query()
            ->with(['creator:id,name', 'updater:id,name'])
            ->latest('updated_at')
            ->get()
            ->each(fn (BusinessProfilePreset $preset) => $preset->setAttribute('configuration', BusinessProfileConfiguration::normalized($preset->configuration ?? [])));

        $sandboxSession = $sandbox->sessionFor($request->user()->id);

        return Inertia::render('SystemSuperadmin/BusinessProfiles/Index', [
            'activeProfile' => $activeProfile,
            'drafts' => $drafts,
            'versions' => $versions,
            'presets' => $presets,
            'options' => BusinessProfileConfiguration::options(),
            'defaultConfiguration' => BusinessProfileConfiguration::defaults(),
            'sandboxSession' => [
                'id' => $sandboxSession->id,
                'name' => $sandboxSession->name,
                'payload' => $sandboxSession->payload,
                'updated_at' => $sandboxSession->updated_at?->toIso8601String(),
                'expires_at' => $sandboxSession->expires_at?->toIso8601String(),
            ],
        ]);
    }

    public function updateSandbox(Request $request, BusinessProfileSandboxSession $session, BusinessProfileSandboxService $sandbox): \Illuminate\Http\JsonResponse
    {
        abort_if($session->user_id !== $request->user()->id, 403, 'No puedes modificar una demo sandbox que pertenece a otro usuario.');

        $data = $request->validate([
            'payload' => ['required', 'array'],
        ]);

        $updated = $sandbox->replacePayload($session, $data['payload']);

        return response()->json([
            'message' => 'Demo sandbox guardada sin afectar produccion.',
            'session' => [
                'id' => $updated->id,
                'payload' => $updated->payload,
                'updated_at' => $updated->updated_at?->toIso8601String(),
            ],
        ]);
    }

    public function resetSandbox(Request $request, BusinessProfileSandboxSession $session, BusinessProfileSandboxService $sandbox): \Illuminate\Http\JsonResponse
    {
        abort_if($session->user_id !== $request->user()->id, 403, 'No puedes reiniciar una demo sandbox que pertenece a otro usuario.');

        $updated = $sandbox->reset($session);

        return response()->json([
            'message' => 'Demo sandbox reiniciada desde datos reales actuales.',
            'session' => [
                'id' => $updated->id,
                'payload' => $updated->payload,
                'updated_at' => $updated->updated_at?->toIso8601String(),
            ],
        ]);
    }

    public function enterFullSandbox(Request $request, BusinessProfileSandboxService $sandbox): RedirectResponse
    {
        $session = $sandbox->sessionFor($request->user()->id);
        $sandbox->provisionDatabase($session);

        $request->session()->put('business_full_sandbox_id', $session->id);

        return redirect()->route('dashboard')->with('success', 'Entraste a la demo completa. Todo lo que hagas queda aislado y no afecta produccion.');
    }

    public function leaveFullSandbox(Request $request): RedirectResponse
    {
        $request->session()->forget('business_full_sandbox_id');

        return redirect()->route('system-superadmin.business-profiles.index')->with('success', 'Saliste de la demo completa. Volviste a produccion.');
    }

    public function discardFullSandbox(Request $request, BusinessProfileSandboxService $sandbox): RedirectResponse
    {
        $session = BusinessProfileSandboxSession::query()
            ->whereKey($request->session()->get('business_full_sandbox_id'))
            ->where('user_id', $request->user()->id)
            ->first();

        $request->session()->forget('business_full_sandbox_id');

        if ($session) {
            $sandbox->discardDatabase($session);
        }

        return redirect()->route('system-superadmin.business-profiles.index')->with('success', 'Demo completa descartada. La base temporal fue eliminada.');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedPayload($request);
        $activeProfile = BusinessProfile::query()->where('status', 'active')->latest('applied_at')->first();

        BusinessProfileDraft::query()->create([
            'name' => $data['name'],
            'business_type' => $data['business_type'],
            'configuration' => BusinessProfileConfiguration::normalized($data['configuration']),
            'source_profile_id' => $activeProfile?->id,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Borrador de configuracion empresarial creado correctamente.');
    }

    public function update(Request $request, BusinessProfileDraft $draft): RedirectResponse
    {
        $data = $this->validatedPayload($request);

        $draft->update([
            'name' => $data['name'],
            'business_type' => $data['business_type'],
            'configuration' => BusinessProfileConfiguration::normalized($data['configuration']),
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Borrador actualizado correctamente. La operacion real no fue afectada.');
    }

    public function apply(Request $request, BusinessProfileDraft $draft): RedirectResponse
    {
        DB::transaction(function () use ($request, $draft) {
            $activeProfile = BusinessProfile::query()
                ->where('status', 'active')
                ->lockForUpdate()
                ->latest('applied_at')
                ->first();

            if ($activeProfile) {
                BusinessProfileVersion::query()->create([
                    'business_profile_id' => $activeProfile->id,
                    'version_number' => $this->nextVersionNumber(),
                    'name' => $activeProfile->name,
                    'business_type' => $activeProfile->business_type,
                    'configuration' => $activeProfile->configuration,
                    'applied_by' => $activeProfile->applied_by,
                    'applied_at' => $activeProfile->applied_at,
                ]);

                $activeProfile->update(['status' => 'archived']);
            }

            BusinessProfile::query()->create([
                'name' => $draft->name,
                'business_type' => $draft->business_type,
                'status' => 'active',
                'configuration' => BusinessProfileConfiguration::normalized($draft->configuration ?? []),
                'applied_at' => now(),
                'applied_by' => $request->user()->id,
            ]);

            $draft->update([
                'status' => 'applied',
                'updated_by' => $request->user()->id,
            ]);
        });

        SystemCacheInvalidator::bumpOperational();

        return back()->with('success', 'Configuracion empresarial aplicada. Las futuras integraciones usaran este perfil activo.');
    }

    public function restore(Request $request, BusinessProfileVersion $version): RedirectResponse
    {
        DB::transaction(function () use ($request, $version) {
            BusinessProfile::query()
                ->where('status', 'active')
                ->update(['status' => 'archived']);

            BusinessProfile::query()->create([
                'name' => $version->name,
                'business_type' => $version->business_type,
                'status' => 'active',
                'configuration' => BusinessProfileConfiguration::normalized($version->configuration ?? []),
                'applied_at' => now(),
                'applied_by' => $request->user()->id,
            ]);
        });

        SystemCacheInvalidator::bumpOperational();

        return back()->with('success', 'Configuracion anterior restaurada correctamente.');
    }

    public function destroy(BusinessProfileDraft $draft): RedirectResponse
    {
        abort_if($draft->status === 'applied', 422, 'No se puede eliminar un borrador ya aplicado.');

        $draft->delete();

        return back()->with('success', 'Borrador descartado correctamente.');
    }

    public function storePreset(Request $request): RedirectResponse
    {
        $data = $this->validatedPayload($request);

        BusinessProfilePreset::query()->create([
            'name' => $data['name'],
            'business_type' => $data['business_type'],
            'description' => $request->string('description')->toString() ?: null,
            'configuration' => BusinessProfileConfiguration::normalized($data['configuration']),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Preset empresarial guardado correctamente para reutilizarlo.');
    }

    public function presetToDraft(Request $request, BusinessProfilePreset $preset): RedirectResponse
    {
        BusinessProfileDraft::query()->create([
            'name' => $preset->name.' - borrador',
            'business_type' => $preset->business_type,
            'configuration' => BusinessProfileConfiguration::normalized($preset->configuration ?? []),
            'source_profile_id' => BusinessProfile::query()->where('status', 'active')->latest('applied_at')->value('id'),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Preset convertido en borrador editable. Produccion no fue afectada.');
    }

    public function destroyPreset(BusinessProfilePreset $preset): RedirectResponse
    {
        abort_if($preset->is_system, 422, 'No se puede eliminar un preset base del sistema.');

        $preset->delete();

        return back()->with('success', 'Preset empresarial eliminado correctamente.');
    }

    private function validatedPayload(Request $request): array
    {
        $options = BusinessProfileConfiguration::options();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'business_type' => ['required', 'string', Rule::in(array_keys($options['businessTypes']))],
            'configuration' => ['required', 'array'],
            'configuration.modules' => ['required', 'array'],
            'configuration.modules.*' => ['boolean'],
            'configuration.sales' => ['required', 'array'],
            'configuration.sales.workflow' => ['required', 'string', Rule::in(array_keys($options['salesWorkflows']))],
            'configuration.sales.quotation_mode' => ['required', 'string', Rule::in(array_keys($options['quotationModes']))],
            'configuration.sales.document_main' => ['required', 'string', Rule::in(array_keys($options['documents']))],
            'configuration.sales.quotation_label' => ['nullable', 'string', 'max:80'],
            'configuration.sales.sale_note_label' => ['nullable', 'string', 'max:80'],
            'configuration.sales.ticket_label' => ['nullable', 'string', 'max:80'],
            'configuration.sales.default_terms' => ['nullable', 'string', 'max:500'],
            'configuration.sales.terms_by_document' => ['nullable', 'array'],
            'configuration.sales.terms_by_document.*' => ['nullable', 'string', 'max:500'],
            'configuration.sales.customer_mode' => ['required', 'string', Rule::in(array_keys($options['entityModes']))],
            'configuration.sales.customer_required' => ['boolean'],
            'configuration.sales.allow_occasional_customer' => ['boolean'],
            'configuration.sales.allow_price_override' => ['required', 'string', Rule::in(['never', 'permission', 'always'])],
            'configuration.sales.allow_negative_stock' => ['boolean'],
            'configuration.sales.negative_stock_policy' => ['required', 'string', Rule::in(array_keys($options['negativeStockPolicies']))],
            'configuration.sales.negative_stock_roles' => ['nullable', 'array'],
            'configuration.sales.negative_stock_roles.*' => ['nullable', 'string', 'max:80'],
            'configuration.sales.negative_stock_categories' => ['nullable', 'array'],
            'configuration.sales.negative_stock_categories.*' => ['nullable', 'string', 'max:120'],
            'configuration.sales.price_policy' => ['required', 'string', Rule::in(array_keys($options['pricePolicies']))],
            'configuration.sales.discount_policy' => ['required', 'string', Rule::in(array_keys($options['discountPolicies']))],
            'configuration.sales.max_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'configuration.sales.discount_roles' => ['nullable', 'array'],
            'configuration.sales.discount_roles.*' => ['nullable', 'string', 'max:80'],
            'configuration.sales.credit_limit_policy' => ['required', 'string', Rule::in(array_keys($options['creditLimitPolicies']))],
            'configuration.sales.default_credit_limit' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'configuration.sales.inventory_discount_timing' => ['required', 'string', Rule::in(array_keys($options['inventoryTimings']))],
            'configuration.sales.visible_columns' => ['nullable', 'array'],
            'configuration.sales.visible_columns.*' => ['string', Rule::in(array_keys($options['saleColumns']))],
            'configuration.sales.allowed_payment_methods' => ['nullable', 'array'],
            'configuration.sales.allowed_payment_methods.*' => ['string', Rule::in(array_keys($options['paymentMethodCodes']))],
            'configuration.sales.payment_methods_by_flow' => ['nullable', 'array'],
            'configuration.sales.payment_methods_by_flow.*' => ['nullable', 'array'],
            'configuration.sales.payment_methods_by_flow.*.*' => ['string', Rule::in(array_keys($options['paymentMethodCodes']))],
            'configuration.purchases' => ['required', 'array'],
            'configuration.purchases.workflow' => ['required', 'string', Rule::in(array_keys($options['purchaseWorkflows']))],
            'configuration.purchases.barcode_entry' => ['boolean'],
            'configuration.purchases.allow_create_product' => ['boolean'],
            'configuration.purchases.supplier_mode' => ['required', 'string', Rule::in(array_keys($options['entityModes']))],
            'configuration.purchases.register_expense_when_paid' => ['boolean'],
            'configuration.deliveries' => ['required', 'array'],
            'configuration.deliveries.mode' => ['required', 'string', Rule::in(array_keys($options['deliveryModes']))],
            'configuration.deliveries.driver_required' => ['boolean'],
            'configuration.deliveries.truck_required' => ['boolean'],
            'configuration.banks' => ['required', 'array'],
            'configuration.banks.reconciliation_mode' => ['required', 'string', Rule::in(array_keys($options['bankReconciliationModes']))],
            'configuration.banks.require_branch_account' => ['boolean'],
            'configuration.billing' => ['required', 'array'],
            'configuration.billing.enabled' => ['boolean'],
            'configuration.billing.mode' => ['required', 'string', Rule::in(['computerized_online', 'electronic_online'])],
            'configuration.billing.document_sector' => ['required', 'string', Rule::in(['compra_venta'])],
            'configuration.billing.invoice_flow' => ['required', 'string', Rule::in(array_keys($options['billingFlows']))],
            'configuration.billing.issue_from' => ['required', 'string', Rule::in(['sale_note', 'direct_sale', 'pos', 'manual_choice'])],
            'configuration.billing.issue_timing' => ['required', 'string', Rule::in(array_keys($options['billingIssueTimings']))],
            'configuration.billing.offline_behavior' => ['required', 'string', Rule::in(['temporary_receipt', 'block', 'queue'])],
            'configuration.billing.require_customer_tax_data' => ['boolean'],
            'configuration.billing.auto_request_cufd' => ['boolean'],
            'configuration.billing.daily_catalog_sync' => ['boolean'],
            'configuration.billing.allow_temporary_receipt' => ['boolean'],
            'configuration.billing.require_product_mapping' => ['boolean'],
            'configuration.billing.block_sale_if_invoice_fails' => ['boolean'],
            'configuration.pos' => ['required', 'array'],
            'configuration.pos.scanner_mode' => ['required', 'string', Rule::in(array_keys($options['scannerModes']))],
            'configuration.pos.cart_merge_rule' => ['required', 'string', Rule::in(['same_product_and_unit', 'same_product_any_unit', 'never'])],
            'configuration.pos.offline_mode' => ['required', 'string', Rule::in(array_keys($options['offlineModes']))],
            'configuration.pos.payment_flow' => ['required', 'string', Rule::in(['single', 'single_or_mixed'])],
            'configuration.pos.customer_prompt' => ['required', 'string', Rule::in(['hidden', 'optional', 'required'])],
            'configuration.products' => ['required', 'array'],
            'configuration.products.catalog_mode' => ['required', 'string', Rule::in(array_keys($options['catalogModes']))],
            'configuration.products.barcode_required' => ['boolean'],
            'configuration.products.barcode_labels' => ['boolean'],
            'configuration.products.unit_equivalences' => ['boolean'],
            'configuration.products.allow_service_items' => ['boolean'],
            'configuration.products.creation_context' => ['required', 'string', Rule::in(array_keys($options['productCreationContexts']))],
            'configuration.human_resources' => ['nullable', 'array'],
            'configuration.human_resources.workers_mode' => ['nullable', 'string', Rule::in(['disabled', 'optional', 'required'])],
            'configuration.human_resources.payroll_enabled' => ['boolean'],
            'configuration.human_resources.salary_expense_integration' => ['boolean'],
            'configuration.cash' => ['required', 'array'],
            'configuration.cash.required_to_sell' => ['boolean'],
            'configuration.cash.scope' => ['required', 'string', Rule::in(array_keys($options['cashScopes']))],
            'configuration.cash.bank_reconciliation' => ['boolean'],
            'configuration.cash.allow_offline_cash_sales' => ['boolean'],
            'configuration.inventory' => ['required', 'array'],
            'configuration.inventory.*' => ['boolean'],
            'configuration.ux' => ['required', 'array'],
            'configuration.ux.*' => ['boolean'],
        ]);

        $data['configuration'] = BusinessProfileConfiguration::normalized($data['configuration']);
        $data['configuration']['sales']['customer_required'] = ($data['configuration']['sales']['customer_mode'] ?? 'required') === 'required';
        $data['configuration']['sales']['allow_negative_stock'] = ($data['configuration']['sales']['negative_stock_policy'] ?? 'never') !== 'never';
        $data['configuration']['modules']['customers'] = ($data['configuration']['sales']['customer_mode'] ?? 'required') !== 'hidden'
            && (bool) ($data['configuration']['modules']['customers'] ?? true);
        $data['configuration']['modules']['suppliers'] = ($data['configuration']['purchases']['supplier_mode'] ?? 'optional') !== 'hidden'
            && (bool) ($data['configuration']['modules']['suppliers'] ?? true);
        $data['configuration']['modules']['deliveries'] = ($data['configuration']['deliveries']['mode'] ?? 'optional') !== 'disabled'
            && (bool) ($data['configuration']['modules']['deliveries'] ?? true);
        $data['configuration']['modules']['banks'] = ($data['configuration']['banks']['reconciliation_mode'] ?? 'automatic') !== 'disabled'
            && (bool) ($data['configuration']['modules']['banks'] ?? true);
        $data['configuration']['billing']['enabled'] = (bool) ($data['configuration']['modules']['billing'] ?? false);

        if (($data['configuration']['billing']['invoice_flow'] ?? 'billing_disabled') === 'billing_disabled') {
            $data['configuration']['modules']['billing'] = false;
            $data['configuration']['billing']['enabled'] = false;
        }

        if (($data['configuration']['billing']['invoice_flow'] ?? null) === 'quote_sale_note_invoice') {
            $data['configuration']['modules']['quotes'] = true;
            $data['configuration']['modules']['sales_notes'] = true;
            $data['configuration']['sales']['quotation_mode'] = 'required';
            $data['configuration']['sales']['workflow'] = 'quotation_to_sale_note';
            $data['configuration']['billing']['issue_from'] = 'sale_note';
        }

        if (($data['configuration']['billing']['invoice_flow'] ?? null) === 'direct_invoice') {
            $data['configuration']['modules']['sales_notes'] = true;
            $data['configuration']['sales']['quotation_mode'] = 'disabled';
            $data['configuration']['sales']['workflow'] = 'direct_sale';
            $data['configuration']['sales']['document_main'] = 'invoice_direct';
            $data['configuration']['billing']['issue_from'] = 'direct_sale';
            $data['configuration']['billing']['issue_timing'] = 'automatic_direct';
        }

        return $data;
    }

    private function nextVersionNumber(): int
    {
        return ((int) BusinessProfileVersion::query()->max('version_number')) + 1;
    }
}
