<?php

namespace App\Console\Commands;

use App\Modules\Integrity\Application\Actions\ArchiveLegacyLedger;
use App\Modules\Integrity\Domain\Ledger\LedgerException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Archives the old system's sealed ledger (110 blocks at the time of writing). The chain must
 * verify as intact before a single block is written. Idempotent; --dry-run only verifies.
 */
#[Signature('ledger:import-legacy
    {--connection=legacy : Database connection of the old system}
    {--dry-run : Verify the old chain and say what would be archived, without writing}')]
#[Description('Archive the old system\'s sealed ledger after verifying it')]
class ImportLegacyLedger extends Command
{
    public function handle(ArchiveLegacyLedger $archive): int
    {
        $rows = DB::connection((string) $this->option('connection'))
            ->table('ledger_blocks')
            ->orderBy('height')
            ->get(['id', 'height', 'kind', 'period_key', 'prev_hash', 'merkle_root', 'payload_hash', 'hash', 'payload', 'sealed_at', 'sealed_by', 'anchor_key'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'height' => (int) $row->height,
                'kind' => (string) $row->kind,
                'period_key' => $row->period_key === null ? null : (string) $row->period_key,
                'prev_hash' => (string) $row->prev_hash,
                'merkle_root' => (string) $row->merkle_root,
                'payload_hash' => (string) $row->payload_hash,
                'hash' => (string) $row->hash,
                'payload' => is_string($row->payload) ? $row->payload : (string) json_encode($row->payload),
                'sealed_at' => (string) $row->sealed_at,
                'sealed_by' => $row->sealed_by === null ? null : (int) $row->sealed_by,
                'anchor_key' => $row->anchor_key === null ? null : (string) $row->anchor_key,
            ])
            ->all();

        try {
            $summary = $archive(array_values($rows), (bool) $this->option('dry-run'));
        } catch (LedgerException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('The old chain is intact: every hash recomputed from the genesis and every link checked.');
        $this->components->twoColumnDetail('Blocks in the old chain', (string) $summary['blocks']);
        $this->components->twoColumnDetail('Head', "height {$summary['height']}, {$summary['head_hash']}");

        if ($this->option('dry-run')) {
            $this->components->warn('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Archived now', (string) $summary['archived']);
        $this->components->twoColumnDetail('Already archived', (string) $summary['already']);

        return self::SUCCESS;
    }
}
