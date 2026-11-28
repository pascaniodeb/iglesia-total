<?php

namespace App\Modules\Integrity\Application;

use App\Modules\Integrity\Domain\Ledger\Canonical;
use App\Modules\Integrity\Infrastructure\Models\LegacyActivityEntry;
use Illuminate\Support\Carbon;

/**
 * The archive of the old system's activity log and the digest that vouches for it. The old log had
 * no chain of its own, so the import writes the digest of what it archived into the new, chained
 * log; from then on the archive can be recomputed and compared with that record.
 *
 * The digest is the SHA-256 of the canonical form of every entry, in the old system's order, with
 * the time normalised to UTC: the same on both sides, whatever the database writes timestamps as.
 */
final class LegacyActivityArchive
{
    /**
     * An entry in the form the digest is computed over.
     *
     * @param  array<string, mixed>  $row  keys of the archive table
     * @return array<string, mixed>
     */
    public static function canonical(array $row): array
    {
        $json = fn (mixed $value): mixed => is_string($value) ? json_decode($value, true) : $value;

        return [
            'id' => (int) $row['legacy_id'],
            'at' => Carbon::parse((string) $row['occurred_at'])->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'actorId' => $row['legacy_actor_id'] === null ? null : (int) $row['legacy_actor_id'],
            'actorName' => (string) $row['actor_name'],
            'actorRole' => (string) $row['actor_role'],
            'action' => (string) $row['action'],
            'entity' => (string) $row['entity'],
            'entityId' => $row['entity_id'] === null ? null : (string) $row['entity_id'],
            'summary' => (string) $row['summary'],
            'before' => $json($row['before'] ?? null),
            'after' => $json($row['after'] ?? null),
            'diff' => $json($row['diff'] ?? null),
            'ip' => $row['ip'] ?? null,
            'userAgent' => $row['user_agent'] ?? null,
        ];
    }

    /**
     * @param  iterable<array<string, mixed>>  $rows  keys of the archive table, in the old system's order
     * @return array{count: int, first: int|null, last: int|null, digest: string}
     */
    public static function summarize(iterable $rows): array
    {
        $canonical = [];

        foreach ($rows as $row) {
            $canonical[] = self::canonical($row);
        }

        return [
            'count' => count($canonical),
            'first' => $canonical === [] ? null : $canonical[0]['id'],
            'last' => $canonical === [] ? null : $canonical[count($canonical) - 1]['id'],
            'digest' => Canonical::hash($canonical),
        ];
    }

    /**
     * What is archived now.
     *
     * @return array{count: int, first: int|null, last: int|null, digest: string}
     */
    public function current(): array
    {
        return self::summarize(
            LegacyActivityEntry::query()->orderBy('legacy_id')->cursor()->map(fn (LegacyActivityEntry $entry): array => $entry->getAttributes()),
        );
    }
}
