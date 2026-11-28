<?php

namespace App\Console\Commands;

use App\Modules\Integrity\Application\Actions\VerifyAndRecordLedger;
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
    public function handle(VerifyAndRecordLedger $verify): int
    {
        $result = $verify();
        $legacy = $result['legacy'];
        $issues = [...$result['issues'], ...$legacy['issues']];

        foreach ($issues as $issue) {
            $this->components->error("[{$issue->type}] #{$issue->height} {$issue->kind} {$issue->periodKey}: {$issue->detail()}");
        }

        foreach ($result['unsealed'] as $pending) {
            $this->components->warn("Seal pending: {$pending['kind']} {$pending['period_key']}.");
        }

        $seals = $result['row_seals'];

        foreach (['changed' => 'changed outside the Actions', 'unsealed' => 'has no seal (run integrity:seal-rows)', 'gone' => 'was erased'] as $kind => $what) {
            foreach ($seals[$kind] as $row) {
                $this->components->error("[row seal] {$row} {$what}.");
            }
        }

        $sealsFail = array_sum($seals['counts']) > 0;

        if ($issues !== [] || $sealsFail) {
            return self::FAILURE;
        }

        $this->components->info("Sealed ledger intact and matching the books: {$result['blocks']} blocks.");
        $this->components->twoColumnDetail('Head height', (string) $result['height']);
        $this->components->twoColumnDetail('Head hash', (string) $result['head_hash']);

        if ($seals['enabled']) {
            $this->components->twoColumnDetail('Row seals', "{$seals['checked']} rows, every seal holds");
        }

        if ($legacy['blocks'] > 0) {
            $this->components->twoColumnDetail('Old chain (archived)', "{$legacy['blocks']} blocks intact, head {$legacy['height']}");
        }

        return self::SUCCESS;
    }
}
