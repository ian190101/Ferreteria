<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Modules\SystemSuperadmin\Models\DynamicReportTemplate;
use Illuminate\Support\Facades\DB;

class BusinessSpecializedReportTemplateInstaller
{
    public function __construct(
        private readonly BusinessSpecializedReportTemplateFactory $templates,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function installForPreset(string $presetName): array
    {
        return DB::transaction(function () use ($presetName): array {
            $summary = ['templates' => 0];

            foreach ($this->templates->forPreset($presetName) as $template) {
                DynamicReportTemplate::query()->updateOrCreate(
                    ['code' => $template['code']],
                    $template,
                );
                $summary['templates']++;
            }

            return $summary;
        });
    }

    /**
     * @return array<string, int>
     */
    public function installAll(): array
    {
        return DB::transaction(function (): array {
            $summary = ['presets' => 0, 'templates' => 0];

            foreach (array_keys($this->templates->all()) as $presetName) {
                $installed = $this->installForPreset($presetName);
                $summary['presets']++;
                $summary['templates'] += $installed['templates'];
            }

            return $summary;
        });
    }
}
