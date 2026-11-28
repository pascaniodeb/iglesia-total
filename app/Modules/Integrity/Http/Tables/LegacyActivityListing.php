<?php

namespace App\Modules\Integrity\Http\Tables;

use App\Modules\Integrity\Infrastructure\Models\LegacyActivityEntry;
use App\Modules\Shared\Tables\TableQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * The archived activity log of the old system, newest first, searchable by who and what. Read-only:
 * the archive has no edit, delete or trash.
 */
final class LegacyActivityListing
{
    public const string DEFAULT_SORT = 'occurred';

    /**
     * @return array<string, string>
     */
    public static function sortable(): array
    {
        return ['occurred' => 'legacy_activity_log.occurred_at'];
    }

    /**
     * @return list<literal-string>
     */
    public static function searchable(): array
    {
        return ['legacy_activity_log.summary', 'legacy_activity_log.actor_name', 'legacy_activity_log.action'];
    }

    /**
     * @return Builder<LegacyActivityEntry>
     */
    public static function base(TableQuery $table): Builder
    {
        return LegacyActivityEntry::query();
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(LegacyActivityEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'legacy_id' => $entry->legacy_id,
            'occurred_at' => $entry->occurred_at->toIso8601String(),
            'actor' => $entry->actor_name,
            'actor_role' => $entry->actor_role,
            'action' => $entry->action,
            'entity' => $entry->entity,
            'summary' => $entry->summary,
            'ip' => $entry->ip,
        ];
    }
}
