<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Models\BusinessProfileDraft;
use App\Support\SystemCacheInvalidator;
use Illuminate\Support\Facades\Cache;

class ActiveBusinessProfile
{
    public static function payload(): array
    {
        if (app()->bound('business_profile_preview_payload')) {
            return app('business_profile_preview_payload');
        }

        return Cache::remember(self::cacheKey(), now()->addMinutes(30), function () {
            $profile = BusinessProfile::query()
                ->where('status', 'active')
                ->orderByDesc('applied_at')
                ->orderByDesc('id')
                ->first();

            $configuration = BusinessProfileConfiguration::normalized($profile?->configuration ?? []);

            return [
                'id' => $profile?->id,
                'name' => $profile?->name ?? 'Ferreteria con cotizacion y nota de venta',
                'commercialName' => $configuration['identity']['commercial_name'] ?: ($profile?->name ?? 'Fabrica de Calaminas'),
                'businessType' => $profile?->business_type ?? 'hardware_store',
                'status' => $profile?->status ?? 'active',
                'configuration' => $configuration,
                'schemaVersion' => $configuration['schema_version'] ?? 2,
                'identity' => $configuration['identity'] ?? [],
                'modules' => $configuration['modules'] ?? [],
                'submodules' => $configuration['submodules'] ?? [],
                'capabilities' => $configuration['capabilities'] ?? [],
                'feature_flags' => $configuration['feature_flags'] ?? [],
                'entities' => $configuration['entities'] ?? [],
                'fields' => $configuration['fields'] ?? [],
                'relationships' => $configuration['relationships'] ?? [],
                'forms' => $configuration['forms'] ?? [],
                'states' => $configuration['states'] ?? [],
                'governance' => $configuration['governance'] ?? [],
                'field_permissions' => $configuration['field_permissions'] ?? [],
                'attachments' => $configuration['attachments'] ?? [],
                'automations' => $configuration['automations'] ?? [],
                'approvals' => $configuration['approvals'] ?? [],
                'integrations' => $configuration['integrations'] ?? [],
                'multibranch' => $configuration['multibranch'] ?? [],
                'sales' => $configuration['sales'] ?? [],
                'purchases' => $configuration['purchases'] ?? [],
                'contacts' => $configuration['contacts'] ?? [],
                'deliveries' => $configuration['deliveries'] ?? [],
                'banks' => $configuration['banks'] ?? [],
                'finance' => $configuration['finance'] ?? [],
                'billing' => $configuration['billing'] ?? [],
                'cash' => $configuration['cash'] ?? [],
                'inventory' => $configuration['inventory'] ?? [],
                'pos' => $configuration['pos'] ?? [],
                'products' => $configuration['products'] ?? [],
                'reservations' => $configuration['reservations'] ?? [],
                'customer_qr_ordering' => $configuration['customer_qr_ordering'] ?? [],
                'ai_assistant' => $configuration['ai_assistant'] ?? [],
                'restaurant' => $configuration['restaurant'] ?? [],
                'services' => $configuration['services'] ?? [],
                'rentals' => $configuration['rentals'] ?? [],
                'production_flow' => $configuration['production_flow'] ?? [],
                'documents' => $configuration['documents'] ?? [],
                'report_templates' => $configuration['report_templates'] ?? [],
                'traceability' => $configuration['traceability'] ?? [],
                'migration' => $configuration['migration'] ?? [],
                'performance' => $configuration['performance'] ?? [],
                'policies' => $configuration['policies'] ?? [],
                'human_resources' => $configuration['human_resources'] ?? [],
                'ux' => $configuration['ux'] ?? [],
            ];
        });
    }

    public static function draftPayload(BusinessProfileDraft $draft): array
    {
        $configuration = BusinessProfileConfiguration::normalized($draft->configuration ?? []);

        return [
            'id' => null,
            'name' => $draft->name,
            'commercialName' => $configuration['identity']['commercial_name'] ?: $draft->name,
            'businessType' => $draft->business_type,
            'status' => 'draft_preview',
            'configuration' => $configuration,
            'schemaVersion' => $configuration['schema_version'] ?? 2,
            'identity' => $configuration['identity'] ?? [],
            'modules' => $configuration['modules'] ?? [],
            'submodules' => $configuration['submodules'] ?? [],
            'capabilities' => $configuration['capabilities'] ?? [],
            'feature_flags' => $configuration['feature_flags'] ?? [],
            'entities' => $configuration['entities'] ?? [],
            'fields' => $configuration['fields'] ?? [],
            'relationships' => $configuration['relationships'] ?? [],
            'forms' => $configuration['forms'] ?? [],
            'states' => $configuration['states'] ?? [],
            'governance' => $configuration['governance'] ?? [],
            'field_permissions' => $configuration['field_permissions'] ?? [],
            'attachments' => $configuration['attachments'] ?? [],
            'automations' => $configuration['automations'] ?? [],
            'approvals' => $configuration['approvals'] ?? [],
            'integrations' => $configuration['integrations'] ?? [],
            'multibranch' => $configuration['multibranch'] ?? [],
            'sales' => $configuration['sales'] ?? [],
            'purchases' => $configuration['purchases'] ?? [],
            'contacts' => $configuration['contacts'] ?? [],
            'deliveries' => $configuration['deliveries'] ?? [],
            'banks' => $configuration['banks'] ?? [],
            'finance' => $configuration['finance'] ?? [],
            'billing' => $configuration['billing'] ?? [],
            'cash' => $configuration['cash'] ?? [],
            'inventory' => $configuration['inventory'] ?? [],
            'pos' => $configuration['pos'] ?? [],
            'products' => $configuration['products'] ?? [],
            'reservations' => $configuration['reservations'] ?? [],
            'customer_qr_ordering' => $configuration['customer_qr_ordering'] ?? [],
            'ai_assistant' => $configuration['ai_assistant'] ?? [],
            'restaurant' => $configuration['restaurant'] ?? [],
            'services' => $configuration['services'] ?? [],
            'rentals' => $configuration['rentals'] ?? [],
            'production_flow' => $configuration['production_flow'] ?? [],
            'documents' => $configuration['documents'] ?? [],
            'report_templates' => $configuration['report_templates'] ?? [],
            'traceability' => $configuration['traceability'] ?? [],
            'migration' => $configuration['migration'] ?? [],
            'performance' => $configuration['performance'] ?? [],
            'policies' => $configuration['policies'] ?? [],
            'human_resources' => $configuration['human_resources'] ?? [],
            'ux' => $configuration['ux'] ?? [],
            'preview' => [
                'active' => true,
                'draft_id' => $draft->id,
                'name' => $draft->name,
                'business_type' => $draft->business_type,
            ],
        ];
    }

    public static function navigationPayload(): array
    {
        $payload = self::payload();
        $configuration = $payload['configuration'] ?? [];

        return [
            'id' => $payload['id'] ?? null,
            'name' => $payload['name'] ?? 'Ferreteria con cotizacion y nota de venta',
            'commercialName' => $payload['commercialName'] ?? ($configuration['identity']['commercial_name'] ?? 'Fabrica de Calaminas'),
            'businessType' => $payload['businessType'] ?? 'hardware_store',
            'status' => $payload['status'] ?? 'active',
            'identity' => $configuration['identity'] ?? [],
            'modules' => $payload['modules'] ?? [],
            'capabilities' => $payload['capabilities'] ?? [],
            'featureFlags' => $payload['feature_flags'] ?? [],
            'preview' => $payload['preview'] ?? ['active' => false],
        ];
    }

    public static function enabled(string $feature): bool
    {
        $modules = self::payload()['modules'] ?? [];

        return (bool) ($modules[$feature] ?? false);
    }

    public static function capable(string $capability): bool
    {
        $capabilities = self::payload()['capabilities'] ?? [];

        return (bool) ($capabilities[$capability] ?? false);
    }

    public static function featureEnabled(string $flag): bool
    {
        $flags = self::payload()['feature_flags'] ?? [];

        return (bool) ($flags[$flag] ?? false);
    }

    public static function salesWorkflow(): string
    {
        return (string) (self::payload()['sales']['workflow'] ?? 'quotation_to_sale_note');
    }

    private static function cacheKey(): string
    {
        return 'business-profile:active:v'.SystemCacheInvalidator::operationalVersion();
    }
}
