<?php

namespace App\Modules\Integrity\Application\Actions;

use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Domain\Ledger\ChainVerifier;
use App\Modules\Integrity\Domain\Ledger\LedgerException;
use App\Modules\Integrity\Domain\Ledger\SealedBlock;
use App\Modules\Integrity\Infrastructure\Models\LegacyLedgerBlock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Copies the old system's sealed ledger into the archive, verbatim, after proving it is intact.
 *
 * The chain is verified with the new chain's own code BEFORE anything is written (every hash
 * recomputed from the genesis, every link checked): a broken or altered old chain is refused, and
 * a chain that verifies is the proof that the port of the hashing is exact. Idempotent: running
 * it again over the same blocks writes nothing; a block that differs from the archived one with
 * the same height is refused (the archive never changes).
 */
final readonly class ArchiveLegacyLedger
{
    public function __construct(private ActivityLog $activityLog) {}

    /**
     * @param  list<array{id: int, height: int, kind: string, period_key: string|null, prev_hash: string, merkle_root: string, payload_hash: string, hash: string, payload: string, sealed_at: string, sealed_by: int|null, anchor_key: string|null}>  $rows  by height, from the genesis
     * @return array{blocks: int, archived: int, already: int, height: int, head_hash: string}
     *
     * @throws LedgerException
     */
    public function __invoke(array $rows, bool $dryRun = false): array
    {
        if ($rows === []) {
            throw new LedgerException('The old system has no sealed ledger to archive.');
        }

        $blocks = array_map(fn (array $row): SealedBlock => new SealedBlock(
            height: (int) $row['height'],
            kind: (string) $row['kind'],
            periodKey: $row['period_key'] === null ? null : (string) $row['period_key'],
            prevHash: (string) $row['prev_hash'],
            merkleRoot: (string) $row['merkle_root'],
            payloadHash: (string) $row['payload_hash'],
            hash: (string) $row['hash'],
            payload: json_decode((string) $row['payload'], false, 512, JSON_THROW_ON_ERROR),
        ), $rows);

        $issues = ChainVerifier::verify($blocks);

        if ($issues !== []) {
            throw new LedgerException(sprintf(
                'The old chain is not intact (%d issues, the first at block %d: %s). Nothing was archived.',
                count($issues),
                $issues[0]->height,
                $issues[0]->detail(),
            ));
        }

        $head = $rows[array_key_last($rows)];
        $summary = ['blocks' => count($rows), 'archived' => 0, 'already' => 0, 'height' => (int) $head['height'], 'head_hash' => (string) $head['hash']];

        if ($dryRun) {
            return $summary;
        }

        DB::transaction(function () use ($rows, &$summary): void {
            foreach ($rows as $row) {
                $existing = LegacyLedgerBlock::query()->where('height', $row['height'])->first();

                if ($existing !== null) {
                    if ($existing->hash !== $row['hash'] || $existing->legacy_id !== (int) $row['id']) {
                        throw new LedgerException("The archived block {$row['height']} differs from the one the old system has now: the archive never changes.");
                    }

                    $summary['already']++;

                    continue;
                }

                DB::table('legacy_ledger_blocks')->insert([
                    'id' => (string) Str::uuid7(),
                    'legacy_id' => $row['id'],
                    'height' => $row['height'],
                    'kind' => $row['kind'],
                    'period_key' => $row['period_key'],
                    'prev_hash' => $row['prev_hash'],
                    'merkle_root' => $row['merkle_root'],
                    'payload_hash' => $row['payload_hash'],
                    'hash' => $row['hash'],
                    'payload' => $row['payload'],
                    'sealed_at' => $row['sealed_at'],
                    'legacy_sealed_by' => $row['sealed_by'],
                    'anchor_key' => $row['anchor_key'],
                ]);
                $summary['archived']++;
            }

            if ($summary['archived'] > 0) {
                $this->activityLog->system(
                    'ledger.legacy_archived',
                    __('Archived the old sealed ledger: :count blocks up to height :height.', ['count' => $summary['archived'], 'height' => $summary['height']]),
                    details: ['blocks' => $summary['blocks'], 'height' => $summary['height'], 'head_hash' => $summary['head_hash']],
                );
            }
        });

        return $summary;
    }
}
