<?php

namespace App\Modules\Integrity\Infrastructure;

use App\Modules\Integrity\Application\LegacyActivityArchive;
use App\Modules\Integrity\Domain\IntegrityCheck;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;

/**
 * The archive of the old activity log is exactly what was archived: its digest, recomputed now,
 * equals the one the import wrote into the chained log.
 */
final class LegacyActivityArchiveCheck implements IntegrityCheck
{
    public function __construct(private readonly LegacyActivityArchive $archive) {}

    public function id(): string
    {
        return 'activity.legacy-archive';
    }

    public function title(): string
    {
        return __('The archive of the old activity log is exactly what was archived');
    }

    public function findings(): array
    {
        $current = $this->archive->current();
        $recorded = ActivityLogEntry::query()->where('action', 'activity.legacy_archived')->orderByDesc('seq')->first();

        if ($recorded === null) {
            return $current['count'] === 0 ? [] : [__('There are :count archived entries but no record of their digest in the activity log.', ['count' => $current['count']])];
        }

        $findings = [];

        if (($recorded->changes['digest'] ?? null) !== $current['digest']) {
            $findings[] = __('The archive no longer matches the digest recorded when it was archived.');
        }

        if ((int) ($recorded->changes['count'] ?? -1) !== $current['count']) {
            $findings[] = __('The archive holds :now entries and :recorded were archived.', ['now' => $current['count'], 'recorded' => (int) ($recorded->changes['count'] ?? 0)]);
        }

        return $findings;
    }
}
