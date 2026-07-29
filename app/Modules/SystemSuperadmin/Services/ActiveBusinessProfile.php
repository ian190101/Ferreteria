<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Support\SystemCacheInvalidator;
use Illuminate\Support\Facades\Cache;

class ActiveBusinessProfile
{
    public static function payload(): array
    {
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
                'businessType' => $profile?->business_type ?? 'hardware_store',
                'status' => $profile?->status ?? 'active',
                'configuration' => $configuration,
                'modules' => $configuration['modules'] ?? [],
                'sales' => $configuration['sales'] ?? [],
                'purchases' => $configuration['purchases'] ?? [],
                'deliveries' => $configuration['deliveries'] ?? [],
                'banks' => $configuration['banks'] ?? [],
                'billing' => $configuration['billing'] ?? [],
                'cash' => $configuration['cash'] ?? [],
                'inventory' => $configuration['inventory'] ?? [],
                'pos' => $configuration['pos'] ?? [],
                'products' => $configuration['products'] ?? [],
                'human_resources' => $configuration['human_resources'] ?? [],
                'ux' => $configuration['ux'] ?? [],
            ];
        });
    }

    public static function navigationPayload(): array
    {
        $payload = self::payload();

        return [
            'id' => $payload['id'] ?? null,
            'name' => $payload['name'] ?? 'Ferreteria con cotizacion y nota de venta',
            'businessType' => $payload['businessType'] ?? 'hardware_store',
            'status' => $payload['status'] ?? 'active',
            'modules' => $payload['modules'] ?? [],
        ];
    }

    public static function enabled(string $feature): bool
    {
        $modules = self::payload()['modules'] ?? [];

        return (bool) ($modules[$feature] ?? false);
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
