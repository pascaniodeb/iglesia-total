<?php

namespace App\Console\Commands;

use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Application\LegacyActivityArchive;
use App\Modules\Integrity\Infrastructure\Models\LegacyActivityEntry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Archives the old system's activity log, entry by entry and verbatim, in a table nobody can edit.
 * The old log had no chain, so what is archived is vouched for by its digest, written into the new
 * chained log. Idempotent: an entry already archived must be equal to the old system's (history is
 * never rewritten) and only the missing ones are added; the archive must end up with exactly the
 * old system's digest or nothing is written.
 */
#[Signature('activity:import-legacy {--connection=legacy : Database connection of the old system} {--dry-run : Report what would be archived without writing}')]
#[Description('Archive the old system\'s activity log')]
class ImportLegacyActivityLog extends Command
{
    private const int CHUNK = 100;

    public function handle(ActivityLog $activityLog, LegacyActivityArchive $archive): int
    {
        $source = DB::connection((string) $this->option('connection'))->table('activity_log')->orderBy('id')->get()
            ->map(fn (object $row): array => [
                'legacy_id' => $row->id, 'occurred_at' => $row->occurred_at, 'legacy_actor_id' => $row->actor_id, 'actor_name' => $row->actor_name,
                'actor_role' => $row->actor_role, 'action' => $row->action, 'entity' => $row->entity, 'entity_id' => $row->entity_id,
                'summary' => $row->summary, 'before' => $row->before, 'after' => $row->after, 'diff' => $row->diff, 'ip' => $row->ip, 'user_agent' => $row->user_agent,
            ])->all();
        $expected = LegacyActivityArchive::summarize($source);
        $this->components->info("Legacy source: {$expected['count']} activity entries.");

        $archived = LegacyActivityEntry::query()->get()->keyBy('legacy_id');
        $bySource = collect($source)->keyBy('legacy_id');

        foreach ($archived as $legacyId => $entry) {
            $row = $bySource->get($legacyId);

            if ($row === null || LegacyActivityArchive::canonical($row) !== LegacyActivityArchive::canonical($entry->getAttributes())) {
                $this->components->error("The archived entry {$legacyId} is not the one of the old system: history is never rewritten, nothing was written.");

                return self::FAILURE;
            }
        }

        $missing = array_values(array_filter($source, fn (array $row): bool => ! $archived->has($row['legacy_id'])));
        $this->components->twoColumnDetail('Already archived', (string) $archived->count());
        $this->components->twoColumnDetail('To archive now', (string) count($missing));

        if ($this->option('dry-run')) {
            $this->components->warn('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        if ($missing === []) {
            $this->components->info('The archive is complete.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($missing, $expected, $archive, $activityLog): void {
            foreach (array_chunk($missing, self::CHUNK) as $chunk) {
                DB::table('legacy_activity_log')->insert(array_map(fn (array $row): array => ['id' => (string) Str::uuid7(), ...$row], $chunk));
            }

            $now = $archive->current();

            if ($now['digest'] !== $expected['digest'] || $now['count'] !== $expected['count']) {
                throw new ImportDifference(['entries' => [(string) $expected['count'], (string) $now['count']], 'digest' => [$expected['digest'], $now['digest']]]);
            }

            $activityLog->system(
                'activity.legacy_archived',
                __('Archived the activity log of the old system: :count entries.', ['count' => $now['count']]),
                details: $now,
            );
        });

        $this->components->info("Archived the old activity log: {$expected['count']} entries, digest ".substr($expected['digest'], 0, 16).'….');

        return self::SUCCESS;
    }
}
