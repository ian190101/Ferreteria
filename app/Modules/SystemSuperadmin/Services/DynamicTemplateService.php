<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Modules\SystemSuperadmin\Models\DynamicDocumentTemplate;
use App\Modules\SystemSuperadmin\Models\DynamicReportTemplate;
use App\Support\SystemCacheInvalidator;
use Illuminate\Support\Facades\Cache;

class DynamicTemplateService
{
    /**
     * @return array<int, DynamicDocumentTemplate>
     */
    public function documentTemplatesFor(string $documentType, ?int $branchId = null, bool $onlyRuntimeEnabled = true): array
    {
        if ($onlyRuntimeEnabled && ! $this->documentRuntimeEnabled()) {
            return [];
        }

        if (! in_array($documentType, ActiveBusinessProfile::payload()['documents']['active'] ?? [], true)) {
            return [];
        }

        return Cache::remember($this->cacheKey('documents', [$documentType, (string) ($branchId ?? 'all')]), $this->cacheTtl(), fn (): array => DynamicDocumentTemplate::query()
            ->with('branch:id,name')
            ->where('document_type', $documentType)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('branch_id')->when($branchId, fn ($scoped) => $scoped->orWhere('branch_id', $branchId)))
            ->orderByRaw('branch_id is null')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->all());
    }

    public function resolveDocument(string $documentType, ?int $branchId = null): ?DynamicDocumentTemplate
    {
        return collect($this->documentTemplatesFor($documentType, $branchId))
            ->first();
    }

    /**
     * @return array<int, DynamicReportTemplate>
     */
    public function reportTemplatesFor(?string $module = null, ?string $entityType = null, bool $onlyRuntimeEnabled = true): array
    {
        if ($onlyRuntimeEnabled && ! $this->reportRuntimeEnabled()) {
            return [];
        }

        if (! ActiveBusinessProfile::enabled('reports')) {
            return [];
        }

        return Cache::remember($this->cacheKey('reports', [(string) ($module ?? 'all'), (string) ($entityType ?? 'all')]), $this->cacheTtl(), fn (): array => DynamicReportTemplate::query()
            ->where('is_active', true)
            ->when($module, fn ($query) => $query->where(fn ($scoped) => $scoped->whereNull('module')->orWhere('module', $module)))
            ->when($entityType, fn ($query) => $query->where(fn ($scoped) => $scoped->whereNull('entity_type')->orWhere('entity_type', $entityType)))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->all());
    }

    public function resolveReport(string $code): ?DynamicReportTemplate
    {
        if (! $this->reportRuntimeEnabled() || ! ActiveBusinessProfile::enabled('reports')) {
            return null;
        }

        return Cache::remember($this->cacheKey('report', [$code]), $this->cacheTtl(), fn (): ?DynamicReportTemplate => DynamicReportTemplate::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->first());
    }

    public function documentRuntimeEnabled(): bool
    {
        return ActiveBusinessProfile::featureEnabled('document_engine_v2')
            && ActiveBusinessProfile::capable('uses_document_templates');
    }

    public function reportRuntimeEnabled(): bool
    {
        return ActiveBusinessProfile::featureEnabled('report_templates_engine')
            && ActiveBusinessProfile::capable('uses_report_templates');
    }

    private function cacheKey(string $scope, array $parts = []): string
    {
        return 'business-dynamic-templates:'.SystemCacheInvalidator::operationalVersion().':'.$scope.':'.implode(':', $parts);
    }

    private function cacheTtl(): \DateTimeInterface
    {
        $minutes = (int) data_get(ActiveBusinessProfile::payload(), 'performance.cache_capabilities_minutes', 60);

        return now()->addMinutes(max($minutes, 1));
    }
}
