<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Models\BusinessProfileDraft;

class BusinessProfileDraftHistoryService
{
    public const STATUS_ACTIVE_SNAPSHOT = 'active_snapshot';

    public const STATUS_HISTORY_SNAPSHOT = 'history_snapshot';

    public static function protectedStatuses(): array
    {
        return [
            self::STATUS_ACTIVE_SNAPSHOT,
            self::STATUS_HISTORY_SNAPSHOT,
        ];
    }

    public function ensureActiveSnapshot(BusinessProfile $profile, ?int $userId = null): BusinessProfileDraft
    {
        return BusinessProfileDraft::query()->updateOrCreate(
            [
                'source_profile_id' => $profile->id,
                'status' => self::STATUS_ACTIVE_SNAPSHOT,
            ],
            [
                'name' => $this->activeSnapshotName($profile),
                'business_type' => $profile->business_type,
                'configuration' => BusinessProfileConfiguration::normalized($profile->configuration ?? []),
                'created_by' => $userId,
                'updated_by' => $userId,
            ]
        );
    }

    public function archiveActiveSnapshot(BusinessProfile $profile, ?int $userId = null): BusinessProfileDraft
    {
        $snapshot = BusinessProfileDraft::query()
            ->where('source_profile_id', $profile->id)
            ->where('status', self::STATUS_ACTIVE_SNAPSHOT)
            ->first();

        if ($snapshot) {
            $snapshot->update([
                'name' => $this->historySnapshotName($profile),
                'business_type' => $profile->business_type,
                'configuration' => BusinessProfileConfiguration::normalized($profile->configuration ?? []),
                'status' => self::STATUS_HISTORY_SNAPSHOT,
                'updated_by' => $userId,
            ]);

            return $snapshot;
        }

        return BusinessProfileDraft::query()->create([
            'name' => $this->historySnapshotName($profile),
            'business_type' => $profile->business_type,
            'status' => self::STATUS_HISTORY_SNAPSHOT,
            'configuration' => BusinessProfileConfiguration::normalized($profile->configuration ?? []),
            'source_profile_id' => $profile->id,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
    }

    private function activeSnapshotName(BusinessProfile $profile): string
    {
        return 'Perfil activo actual - '.$profile->name;
    }

    private function historySnapshotName(BusinessProfile $profile): string
    {
        $appliedAt = $profile->applied_at?->format('Y-m-d H:i') ?? $profile->updated_at?->format('Y-m-d H:i') ?? now()->format('Y-m-d H:i');

        return 'Historial '.$appliedAt.' - '.$profile->name;
    }
}
