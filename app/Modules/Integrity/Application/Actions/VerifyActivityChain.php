<?php

namespace App\Modules\Integrity\Application\Actions;

use Illuminate\Support\Facades\DB;

/**
 * Recomputes every link of the activity log chain.
 *
 * A link is broken when its hash does not match its content (the row was edited) or when it
 * does not point to the hash of the row before it (a row in the middle was removed or
 * reordered). The formula lives in PostgreSQL (`activity_log_hash`), the same function the
 * insert trigger uses, so verification and writing can never disagree.
 *
 * What a chain alone cannot prove: that the newest rows were not cut off the end. That is
 * covered by publishing the head (seq + hash) outside the database: it is printed by
 * `activity:verify` and is anchored externally in a later phase.
 */
final class VerifyActivityChain
{
    /**
     * @return array{rows: int, broken: list<int>, head_seq: int|null, head_hash: string|null}
     */
    public function __invoke(int $limit = 20): array
    {
        $broken = DB::select(<<<'SQL'
            SELECT seq FROM (
                SELECT seq, hash, previous_hash,
                       lag(hash) OVER (ORDER BY seq) AS before,
                       activity_log_hash(previous_hash, a) AS expected
                FROM activity_log a
            ) chain
            WHERE hash IS DISTINCT FROM expected OR previous_hash IS DISTINCT FROM before
            ORDER BY seq
            LIMIT ?
        SQL, [$limit]);

        $head = DB::table('activity_log')->orderByDesc('seq')->first(['seq', 'hash']);

        return [
            'rows' => DB::table('activity_log')->count(),
            'broken' => array_map(intval(...), array_column($broken, 'seq')),
            'head_seq' => $head === null ? null : (int) $head->seq,
            'head_hash' => $head === null ? null : trim((string) $head->hash),
        ];
    }
}
