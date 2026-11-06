<?php

namespace App\Console\Commands;

use App\Modules\Integrity\Application\Actions\VerifyLedgerChain;
use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Domain\Ledger\BlockIssue;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Verifies the sealed ledger (chain and books) and records the verification in the activity
 * log. Prints the head so it can be kept outside the database. Fails on any issue.
 */
#[Signature('ledger:verify')]
#[Description('Verify the sealed ledger chain against itself and against the books')]
class VerifyLedger extends Command
{
    public function handle(VerifyLedgerChain $verify, ActivityLog $activityLog): int
    {
        $result = $verify();
        $issues = $result['issues'];

        $activityLog->system(
            'ledger.verified',
            $issues === []
                ? __('Verified the sealed ledger: :blocks blocks, intact and matching the books.', ['blocks' => $result['blocks']])
                : __('Verified the sealed ledger: :count issues found.', ['count' => count($issues)]),
            details: [
                'blocks' => $result['blocks'],
                'height' => $result['height'],
                'head_hash' => $result['head_hash'],
                'intact' => $result['intact'],
                'matches_books' => $result['matches_books'],
                'issues' => array_map(fn (BlockIssue $issue) => $issue->toArray(), $issues),
                'unsealed' => $result['unsealed'],
            ],
        );

        foreach ($issues as $issue) {
            $this->components->error("[{$issue->type}] #{$issue->height} {$issue->kind} {$issue->periodKey}: {$issue->detail()}");
        }

        foreach ($result['unsealed'] as $pending) {
            $this->components->warn("Seal pending: {$pending['kind']} {$pending['period_key']}.");
        }

        if ($issues !== []) {
            return self::FAILURE;
        }

        $this->components->info("Sealed ledger intact and matching the books: {$result['blocks']} blocks.");
        $this->components->twoColumnDetail('Head height', (string) $result['height']);
        $this->components->twoColumnDetail('Head hash', (string) $result['head_hash']);

        return self::SUCCESS;
    }
}
