<?php

namespace App\Modules\Integrity\Http\Tables;

use App\Enums\UserRole;
use App\Modules\Integrity\Domain\ChangeSet;
use App\Modules\Integrity\Domain\Enums\ActivityCategory;
use App\Modules\Integrity\Domain\Enums\ActivityChannel;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Shared\Tables\TableQuery;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * The activity log listing: newest first, searchable by who and what, filtered by category.
 * Read-only by nature: the log has no edit, delete or trash.
 */
final class ActivityLogListing
{
    public const string DEFAULT_SORT = 'occurred';

    /**
     * @return array<string, string>
     */
    public static function sortable(): array
    {
        return [
            'occurred' => 'activity_log.occurred_at',
        ];
    }

    /**
     * Who and what, through the trigram index of the generated search column.
     *
     * @return list<Closure(Builder<ActivityLogEntry>, string): mixed>
     */
    public static function searchable(): array
    {
        return [
            fn (Builder $query, string $pattern) => $query->where('activity_log.search_text', 'like', $pattern),
        ];
    }

    /**
     * @return array<string, Closure(string, array<string, string|null>): bool>
     */
    public static function facets(): array
    {
        return [
            'category' => fn (string $value) => ActivityCategory::tryFrom($value) !== null,
        ];
    }

    /**
     * @return Builder<ActivityLogEntry>
     */
    public static function base(TableQuery $table): Builder
    {
        return ActivityLogEntry::query()
            ->when($table->facet('category'), fn (Builder $query, string $category) => $query->where('activity_log.category', $category));
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(ActivityLogEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'seq' => $entry->seq,
            'occurred_at' => $entry->occurred_at->toIso8601String(),
            'category' => $entry->category->value,
            'category_label' => $entry->category->label(),
            'actor' => self::actor($entry),
            'actor_role' => $entry->actor_role === null ? null : UserRole::tryFrom($entry->actor_role)?->label(),
            'summary' => $entry->summary,
            'action' => $entry->action,
            'changes' => self::changes($entry->changes),
            'ip' => $entry->ip,
            'route' => $entry->route,
            'request_id' => $entry->request_id,
            'hash' => trim($entry->hash),
        ];
    }

    /**
     * The author as a person reads it: the name at the time, or who acts when nobody is
     * signed in.
     */
    private static function actor(ActivityLogEntry $entry): string
    {
        return match ($entry->channel) {
            ActivityChannel::Web => (string) $entry->actor_name,
            ActivityChannel::System => __('System'),
            ActivityChannel::Guest => __('Not signed in'),
        };
    }

    /**
     * Each change with the field name in Spanish (the form's own labels) and the values as
     * they were recorded, sorted by that label (jsonb stores keys by length, which reads as
     * random). Values that were never logged come as `protected`.
     *
     * @param  array<string, mixed>  $changes
     * @return list<array{field: string, label: string, from: mixed, to: mixed, protected: bool}>
     */
    private static function changes(array $changes): array
    {
        $rows = [];

        foreach ($changes as $field => $change) {
            $isChange = is_array($change) && array_key_exists('from', $change) && array_key_exists('to', $change);
            $from = $isChange ? $change['from'] : null;
            $to = $isChange ? $change['to'] : $change;
            $key = "validation.attributes.{$field}";

            $rows[] = [
                'field' => (string) $field,
                'label' => Lang::has($key) ? ucfirst((string) __($key)) : (string) $field,
                'from' => $from,
                'to' => $to,
                'protected' => $from === ChangeSet::REDACTED || $to === ChangeSet::REDACTED,
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcmp(Str::lower(Str::ascii($a['label'])), Str::lower(Str::ascii($b['label']))));

        return $rows;
    }
}
