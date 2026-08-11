<?php

namespace App\Modules\SystemSuperadmin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Models\BusinessProfileDraft;
use App\Modules\SystemSuperadmin\Models\BusinessProfilePreset;
use App\Modules\SystemSuperadmin\Models\BusinessProfileSandboxSession;
use App\Modules\SystemSuperadmin\Models\BusinessProfileVersion;
use App\Modules\SystemSuperadmin\Services\BusinessProfileActivationChecklist;
use App\Modules\SystemSuperadmin\Services\BusinessCapabilityCatalog;
use App\Modules\SystemSuperadmin\Services\BusinessProfileCompatibilityValidator;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use App\Modules\SystemSuperadmin\Services\BusinessProfileDiffService;
use App\Modules\SystemSuperadmin\Services\BusinessProfileMigrationMapper;
use App\Modules\SystemSuperadmin\Services\BusinessProfileSandboxService;
use App\Modules\SystemSuperadmin\Services\BusinessPresetReadinessService;
use App\Support\SystemCacheInvalidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

        $diffService = app(BusinessProfileDiffService::class);

        $activationChecklist = app(BusinessProfileActivationChecklist::class);
        $presetReadiness = app(BusinessPresetReadinessService::class);

        return Inertia::render('SystemSuperadmin/BusinessProfiles/Index', [
            'activeProfile' => $activeProfile,
            'drafts' => $drafts,
            'activePreflight' => $activeProfile
                ? $activationChecklist->evaluate($activeProfile->business_type, $activeProfile->configuration ?? [])
                : null,
            'draftPreflights' => $drafts
                ->mapWithKeys(fn (BusinessProfileDraft $draft) => [
                    $draft->id => $activationChecklist->evaluate($draft->business_type, $draft->configuration ?? []),
                ]),
            'versions' => $versions,
            'versionComparisons' => $versions
                ->mapWithKeys(fn (BusinessProfileVersion $version) => [
                    $version->id => $activeProfile
                        ? $diffService->compare($version->configuration ?? [], $activeProfile->configuration ?? [])
                        : [],
                ]),
            'presets' => $presets,
            'presetReadiness' => collect($presetReadiness->all())->keyBy('name')->all(),
            'options' => BusinessProfileConfiguration::options(),
            'defaultConfiguration' => BusinessProfileConfiguration::defaults(),
            'capabilitiesCatalog' => BusinessCapabilityCatalog::all(),
            'capabilitiesMatrix' => BusinessCapabilityCatalog::matrix(),
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
        if ($request->session()->has('business_full_sandbox_id')) {
            return redirect()
                ->route('dashboard')
                ->with('warning', 'Ya estas dentro de una demo completa. Sal de la demo actual antes de iniciar otra.');
        }

        $session = $sandbox->sessionFor($request->user()->id);

        try {
            $sandbox->provisionDatabase($session);
        } catch (\RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

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
        $this->validateDraftBeforeApply($draft);

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
            'configuration.schema_version' => ['nullable', 'integer', 'min:2', 'max:2'],
            'configuration.identity' => ['nullable', 'array'],
            'configuration.identity.business_type' => ['nullable', 'string', Rule::in(array_keys($options['businessTypes']))],
            'configuration.identity.commercial_name' => ['nullable', 'string', 'max:160'],
            'configuration.identity.specific_industry' => ['nullable', 'string', 'max:160'],
            'configuration.identity.*' => ['nullable'],
            'configuration.modules' => ['required', 'array'],
            'configuration.modules.*' => ['boolean'],
            'configuration.submodules' => ['nullable', 'array'],
            'configuration.submodules.*' => ['boolean'],
            'configuration.capabilities' => ['nullable', 'array'],
            'configuration.capabilities.*' => ['boolean'],
            'configuration.feature_flags' => ['nullable', 'array'],
            'configuration.feature_flags.*' => ['boolean'],
            'configuration.entities' => ['nullable', 'array'],
            'configuration.entities.defaults_locked' => ['nullable', 'boolean'],
            'configuration.entities.degraded_mode' => ['nullable', 'string', Rule::in(['read_only_history', 'hidden', 'fully_disabled'])],
            'configuration.entities.definitions' => ['nullable', 'array'],
            'configuration.fields' => ['nullable', 'array'],
            'configuration.fields.defaults_locked' => ['nullable', 'boolean'],
            'configuration.fields.max_fields_per_entity' => ['nullable', 'integer', 'min:0', 'max:200'],
            'configuration.fields.sensitive_fields_require_permission' => ['nullable', 'boolean'],
            'configuration.fields.definitions' => ['nullable', 'array'],
            'configuration.relationships' => ['nullable', 'array'],
            'configuration.relationships.defaults_locked' => ['nullable', 'boolean'],
            'configuration.relationships.definitions' => ['nullable', 'array'],
            'configuration.forms' => ['nullable', 'array'],
            'configuration.forms.defaults_locked' => ['nullable', 'boolean'],
            'configuration.forms.definitions' => ['nullable', 'array'],
            'configuration.states' => ['nullable', 'array'],
            'configuration.states.defaults_locked' => ['nullable', 'boolean'],
            'configuration.states.definitions' => ['nullable', 'array'],
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
            'configuration.sales.item_merge_control_enabled' => ['boolean'],
            'configuration.sales.item_merge_rule' => ['required', 'string', Rule::in(array_keys($options['itemMergeRules']))],
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
            'configuration.purchases.purchase_stock_mode_choice' => ['boolean'],
            'configuration.contacts' => ['nullable', 'array'],
            'configuration.contacts.customer_mode' => ['nullable', 'string', Rule::in(array_keys($options['entityModes']))],
            'configuration.contacts.supplier_mode' => ['nullable', 'string', Rule::in(array_keys($options['entityModes']))],
            'configuration.contacts.delivery_address_mode' => ['nullable', 'string', Rule::in(array_keys($options['entityModes']))],
            'configuration.contacts.*' => ['nullable'],
            'configuration.deliveries' => ['required', 'array'],
            'configuration.deliveries.mode' => ['required', 'string', Rule::in(array_keys($options['deliveryModes']))],
            'configuration.deliveries.driver_required' => ['boolean'],
            'configuration.deliveries.truck_required' => ['boolean'],
            'configuration.banks' => ['required', 'array'],
            'configuration.banks.reconciliation_mode' => ['required', 'string', Rule::in(array_keys($options['bankReconciliationModes']))],
            'configuration.banks.require_branch_account' => ['boolean'],
            'configuration.finance' => ['nullable', 'array'],
            'configuration.finance.accounting_mode' => ['nullable', 'string', Rule::in(array_keys($options['accountingModes']))],
            'configuration.finance.*' => ['nullable'],
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
            'configuration.products.item_types' => ['nullable', 'array'],
            'configuration.products.item_types.*' => ['string', Rule::in(array_keys($options['itemTypes']))],
            'configuration.products.images_enabled' => ['boolean'],
            'configuration.products.gallery_enabled' => ['boolean'],
            'configuration.products.variants_enabled' => ['boolean'],
            'configuration.products.custom_fields_enabled' => ['boolean'],
            'configuration.products.barcode_required' => ['boolean'],
            'configuration.products.barcode_labels' => ['boolean'],
            'configuration.products.unit_equivalences' => ['boolean'],
            'configuration.products.allow_service_items' => ['boolean'],
            'configuration.products.creation_context' => ['required', 'string', Rule::in(array_keys($options['productCreationContexts']))],
            'configuration.reservations' => ['nullable', 'array'],
            'configuration.reservations.*' => ['nullable'],
            'configuration.restaurant' => ['nullable', 'array'],
            'configuration.restaurant.mode' => ['nullable', 'string', Rule::in(array_keys($options['restaurantModes']))],
            'configuration.restaurant.*' => ['nullable'],
            'configuration.services' => ['nullable', 'array'],
            'configuration.services.mode' => ['nullable', 'string', Rule::in(array_keys($options['serviceModes']))],
            'configuration.services.*' => ['nullable'],
            'configuration.rentals' => ['nullable', 'array'],
            'configuration.rentals.*' => ['nullable'],
            'configuration.production_flow' => ['nullable', 'array'],
            'configuration.production_flow.*' => ['nullable'],
            'configuration.documents' => ['nullable', 'array'],
            'configuration.documents.active' => ['nullable', 'array'],
            'configuration.documents.active.*' => ['string', Rule::in(array_keys($options['documentTypes']))],
            'configuration.documents.numbering' => ['nullable', 'array'],
            'configuration.documents.numbering.*' => ['boolean'],
            'configuration.governance' => ['nullable', 'array'],
            'configuration.governance.soft_delete_by_default' => ['nullable', 'boolean'],
            'configuration.governance.retention_policy' => ['nullable', 'string', Rule::in(array_keys($options['governanceRetentionPolicies']))],
            'configuration.governance.allow_anonymization' => ['nullable', 'boolean'],
            'configuration.governance.sensitive_entities' => ['nullable', 'array'],
            'configuration.governance.sensitive_entities.*' => ['string', 'max:120'],
            'configuration.governance.export_sensitive_requires_permission' => ['nullable', 'boolean'],
            'configuration.governance.audit_sensitive_reads' => ['nullable', 'boolean'],
            'configuration.field_permissions' => ['nullable', 'array'],
            'configuration.field_permissions.mode' => ['nullable', 'string', Rule::in(array_keys($options['fieldPermissionModes']))],
            'configuration.field_permissions.default_sensitive_visibility' => ['nullable', 'string', Rule::in(['deny', 'read_only', 'allow'])],
            'configuration.field_permissions.rules' => ['nullable', 'array'],
            'configuration.field_permissions.rules.*.entity' => ['nullable', 'string', 'max:120'],
            'configuration.field_permissions.rules.*.field' => ['nullable', 'string', 'max:120'],
            'configuration.field_permissions.rules.*.actions' => ['nullable', 'array'],
            'configuration.field_permissions.rules.*.actions.*' => ['string', Rule::in(['view', 'edit', 'export'])],
            'configuration.field_permissions.rules.*.roles' => ['nullable', 'array'],
            'configuration.field_permissions.rules.*.roles.*' => ['string', 'max:120'],
            'configuration.field_permissions.rules.*.users' => ['nullable', 'array'],
            'configuration.field_permissions.rules.*.users.*' => ['integer', 'min:1'],
            'configuration.field_permissions.rules.*.branches' => ['nullable', 'array'],
            'configuration.field_permissions.rules.*.branches.*' => ['integer', 'min:1'],
            'configuration.field_permissions.rules.*.flows' => ['nullable', 'array'],
            'configuration.field_permissions.rules.*.flows.*' => ['string', 'max:120'],
            'configuration.field_permissions.rules.*.states' => ['nullable', 'array'],
            'configuration.field_permissions.rules.*.states.*' => ['string', 'max:120'],
            'configuration.field_permissions.rules.*.effect' => ['nullable', 'string', Rule::in(['allow', 'deny'])],
            'configuration.attachments' => ['nullable', 'array'],
            'configuration.attachments.enabled' => ['nullable', 'boolean'],
            'configuration.attachments.storage' => ['nullable', 'string', Rule::in(array_keys($options['attachmentStorageModes']))],
            'configuration.attachments.max_file_mb' => ['nullable', 'integer', 'min:1', 'max:100'],
            'configuration.attachments.allowed_types' => ['nullable', 'array'],
            'configuration.attachments.allowed_types.*' => ['string', 'max:20'],
            'configuration.attachments.signed_urls' => ['nullable', 'boolean'],
            'configuration.attachments.audit_downloads' => ['nullable', 'boolean'],
            'configuration.attachments.retention_policy' => ['nullable', 'string', 'max:80'],
            'configuration.automations' => ['nullable', 'array'],
            'configuration.automations.enabled' => ['nullable', 'boolean'],
            'configuration.automations.rules' => ['nullable', 'array'],
            'configuration.approvals' => ['nullable', 'array'],
            'configuration.approvals.enabled' => ['nullable', 'boolean'],
            'configuration.approvals.rules' => ['nullable', 'array'],
            'configuration.integrations' => ['nullable', 'array'],
            'configuration.integrations.enabled' => ['nullable', 'boolean'],
            'configuration.integrations.channels' => ['nullable', 'array'],
            'configuration.integrations.channels.*' => ['boolean'],
            'configuration.report_templates' => ['nullable', 'array'],
            'configuration.report_templates.enabled' => ['nullable', 'boolean'],
            'configuration.report_templates.templates' => ['nullable', 'array'],
            'configuration.traceability' => ['nullable', 'array'],
            'configuration.traceability.enabled' => ['nullable', 'boolean'],
            'configuration.traceability.capture_operational_responsibles' => ['nullable', 'boolean'],
            'configuration.traceability.events' => ['nullable', 'array'],
            'configuration.traceability.events.*' => ['boolean'],
            'configuration.migration' => ['nullable', 'array'],
            'configuration.migration.preserve_legacy_behavior' => ['nullable', 'boolean'],
            'configuration.migration.legacy_profile_name' => ['nullable', 'string', 'max:160'],
            'configuration.migration.dynamic_structure_version' => ['nullable', 'integer', 'min:1', 'max:99'],
            'configuration.migration.allow_destructive_migrations' => ['nullable', 'boolean'],
            'configuration.migration.requires_regression_tests' => ['nullable', 'boolean'],
            'configuration.performance' => ['nullable', 'array'],
            'configuration.performance.cache_profile_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'configuration.performance.cache_capabilities_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'configuration.performance.max_dynamic_fields_per_entity' => ['nullable', 'integer', 'min:0', 'max:200'],
            'configuration.performance.precompute_dynamic_reports' => ['nullable', 'boolean'],
            'configuration.performance.paginated_dynamic_lists' => ['nullable', 'boolean'],
            'configuration.policies' => ['nullable', 'array'],
            'configuration.policies.data' => ['nullable', 'array'],
            'configuration.policies.data.sensitive_fields' => ['nullable', 'array'],
            'configuration.policies.data.sensitive_fields.*' => ['string', Rule::in(array_keys($options['sensitiveDataFields']))],
            'configuration.policies.data.*' => ['nullable'],
            'configuration.policies.voids_returns' => ['nullable', 'array'],
            'configuration.policies.voids_returns.*' => ['nullable'],
            'configuration.policies.degraded_mode' => ['nullable', 'array'],
            'configuration.policies.degraded_mode.*' => ['boolean'],
            'configuration.compatibility' => ['nullable', 'array'],
            'configuration.activation_checklist' => ['nullable', 'array'],
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

        $data['configuration'] = app(BusinessProfileMigrationMapper::class)
            ->toVersionTwo($data['business_type'], $data['configuration']);
        $data['configuration']['identity']['business_type'] = $data['business_type'];
        $data['configuration']['contacts']['customer_mode'] = $data['configuration']['sales']['customer_mode'] ?? 'required';
        $data['configuration']['contacts']['supplier_mode'] = $data['configuration']['purchases']['supplier_mode'] ?? 'optional';
        $data['configuration']['contacts']['tax_data_required_for_billing'] = (bool) ($data['configuration']['billing']['require_customer_tax_data'] ?? true);
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

        $data['configuration']['capabilities']['uses_inventory'] = (bool) ($data['configuration']['modules']['inventory'] ?? false);
        $data['configuration']['capabilities']['uses_pos'] = (bool) ($data['configuration']['modules']['pos'] ?? false);
        $data['configuration']['capabilities']['uses_billing'] = (bool) ($data['configuration']['modules']['billing'] ?? false);
        $data['configuration']['capabilities']['uses_cash'] = (bool) ($data['configuration']['modules']['cash'] ?? false);
        $data['configuration']['capabilities']['uses_cash_flow'] = (bool) ($data['configuration']['modules']['cash_flow'] ?? false);
        $data['configuration']['capabilities']['uses_banks'] = (bool) ($data['configuration']['modules']['banks'] ?? false);
        $data['configuration']['capabilities']['uses_delivery'] = (bool) ($data['configuration']['modules']['deliveries'] ?? false);
        $data['configuration']['capabilities']['uses_services'] = (bool) ($data['configuration']['modules']['services'] ?? false);
        $data['configuration']['capabilities']['uses_service_orders'] = (bool) ($data['configuration']['modules']['service_orders'] ?? false);
        $data['configuration']['capabilities']['uses_tables'] = (bool) ($data['configuration']['modules']['restaurant_tables'] ?? false);
        $data['configuration']['capabilities']['uses_kitchen_orders'] = (bool) ($data['configuration']['modules']['kitchen_orders'] ?? false);
        $data['configuration']['capabilities']['uses_recipes'] = (bool) ($data['configuration']['modules']['recipes'] ?? false);
        $data['configuration']['capabilities']['uses_rentals'] = (bool) ($data['configuration']['modules']['rentals'] ?? false);
        $data['configuration']['capabilities']['uses_production'] = (bool) ($data['configuration']['modules']['production'] ?? false);
        $data['configuration']['capabilities']['requires_cash_session'] = (bool) ($data['configuration']['cash']['required_to_sell'] ?? false);
        $data['configuration']['capabilities']['requires_customer'] = ($data['configuration']['sales']['customer_mode'] ?? 'required') === 'required';
        $data['configuration']['capabilities']['allows_quote_to_sale'] = (bool) ($data['configuration']['modules']['quotes'] ?? false);
        $data['configuration']['capabilities']['allows_direct_sale'] = in_array($data['configuration']['sales']['workflow'] ?? '', ['direct_sale', 'pos', 'optional_quotation', 'restaurant_counter'], true);

        $compatibility = app(BusinessProfileCompatibilityValidator::class)->validate($data['business_type'], $data['configuration']);

        if (! $compatibility['valid']) {
            throw ValidationException::withMessages([
                'configuration' => $compatibility['errors'],
            ]);
        }

        $data['configuration']['compatibility']['last_warnings'] = $compatibility['warnings'];
        $data['configuration']['compatibility']['last_checked_at'] = now()->toIso8601String();

        return $data;
    }

    private function nextVersionNumber(): int
    {
        return ((int) BusinessProfileVersion::query()->max('version_number')) + 1;
    }

    private function validateDraftBeforeApply(BusinessProfileDraft $draft): void
    {
        $configuration = BusinessProfileConfiguration::normalized($draft->configuration ?? []);
        $compatibility = app(BusinessProfileCompatibilityValidator::class)->validate($draft->business_type, $configuration);
        $preflight = app(BusinessProfileActivationChecklist::class)->evaluate($draft->business_type, $configuration);

        if (! $compatibility['valid']) {
            throw ValidationException::withMessages([
                'configuration' => array_merge(
                    ['No se puede aplicar este borrador porque tiene reglas incompatibles. Corrigelo y vuelve a guardarlo.'],
                    $compatibility['errors'],
                ),
            ]);
        }

        if (! $preflight['ready']) {
            throw ValidationException::withMessages([
                'configuration' => array_merge(
                    ['No se puede aplicar este borrador porque el checklist de activacion tiene pendientes criticos.'],
                    collect($preflight['blockers'])->pluck('message')->all(),
                ),
            ]);
        }
    }
}
