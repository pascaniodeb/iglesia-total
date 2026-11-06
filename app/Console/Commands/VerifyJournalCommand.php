<?php

namespace App\Console\Commands;

use App\Modules\Accounting\Application\Actions\VerifyJournal;
use App\Modules\Integrity\Application\ActivityLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Recomputes the journal's arithmetic (global balance per currency, every entry, every
 * reversal), records the verification in the activity log and fails on any finding.
 */
#[Signature('journal:verify')]
#[Description('Verify that the journal balances in every currency, entry by entry')]
class VerifyJournalCommand extends Command
{
    public function handle(VerifyJournal $verify, ActivityLog $activityLog): int
    {
        $result = $verify();

        $activityLog->system(
            'journal.verified',
            $result['passed']
                ? __('Verified the journal: :entries entries, balanced in every currency.', ['entries' => $result['entries']])
                : __('Verified the journal: it does not balance.'),
            details: $result,
        );

        foreach ($result['currencies'] as $currency => $totals) {
            $this->components->twoColumnDetail(
                $currency,
                "debit {$totals['debit']} · credit {$totals['credit']}".($totals['balanced'] ? '' : ' · UNBALANCED'),
            );
        }

        if (! $result['passed']) {
            foreach ($result['unbalanced_entries'] as $id) {
                $this->components->error("Unbalanced entry: {$id}.");
            }

            foreach ($result['mismatched_reversals'] as $id) {
                $this->components->error("Reversal that does not mirror its original: {$id}.");
            }

            return self::FAILURE;
        }

        $this->components->info("Journal balanced: {$result['entries']} entries.");

        return self::SUCCESS;
    }
}
