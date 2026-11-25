<?php

namespace App\Console\Commands;

use App\Console\Reconciliation\BooksVerification;
use App\Modules\Integrity\Application\ActivityLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The permanent controls between the treasury and the accounting books: closed weeks and months
 * equal their offerings, the income posted equals the closures, the snapshots equal the journal and
 * every receipt moved the cash by its amounts. Records the run in the activity log; fails on any
 * finding.
 */
#[Signature('books:verify')]
#[Description('Cross-check the treasury against the accounting books')]
class VerifyBooks extends Command
{
    public function handle(BooksVerification $verify, ActivityLog $activityLog): int
    {
        $result = $verify();
        $failing = array_filter($result, fn (array $check): bool => $check['findings'] !== []);

        $activityLog->system(
            'books.verified',
            $failing === []
                ? __('Cross-checked the treasury against the books: every control passed.')
                : __('Cross-checked the treasury against the books: :count controls failed.', ['count' => count($failing)]),
            details: array_map(fn (array $check): array => ['title' => $check['title'], 'findings' => $check['findings']], $result),
        );

        foreach ($result as $check) {
            $this->components->twoColumnDetail($check['title'], $check['findings'] === [] ? 'ok' : '<fg=red>'.count($check['findings']).' findings</>');

            foreach ($check['findings'] as $finding) {
                $this->components->error($finding);
            }
        }

        return $failing === [] ? self::SUCCESS : self::FAILURE;
    }
}
