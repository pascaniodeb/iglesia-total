<?php

namespace App\Modules\Integrity\Application\Actions;

use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Domain\Ledger\BlockIssue;

/**
 * Runs the two passes of the sealed ledger verification and leaves the result in the activity
 * log, the same way whoever asks: the schedule, a command or a person at the integrity center.
 */
final readonly class VerifyAndRecordLedger
{
    public function __construct(private VerifyLedgerChain $verify, private ActivityLog $activityLog) {}

    /**
     * @return array{
     *     blocks: int,
     *     height: int|null,
     *     head_hash: string|null,
     *     issues: list<BlockIssue>,
     *     intact: bool,
     *     matches_books: bool,
     *     unsealed: list<array{kind: string, period_key: string}>,
     *     legacy: array{blocks: int, height: int|null, head_hash: string|null, issues: list<BlockIssue>, intact: bool}
     * }
     */
    public function __invoke(): array
    {
        $result = ($this->verify)();
        $legacy = $result['legacy'];
        $issues = [...$result['issues'], ...$legacy['issues']];

        $this->activityLog->system(
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
                'legacy' => ['blocks' => $legacy['blocks'], 'height' => $legacy['height'], 'head_hash' => $legacy['head_hash'], 'intact' => $legacy['intact']],
            ],
        );

        return $result;
    }
}
