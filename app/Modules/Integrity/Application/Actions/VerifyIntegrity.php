<?php

namespace App\Modules\Integrity\Application\Actions;

use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Application\IntegrityChecks;

/**
 * Everything the integrity center verifies, in one go and on the record: the chain (both passes and
 * the old chain's archive) and every check the modules registered. Each leaves its own entry in the
 * activity log; the center shows them in its history.
 */
final readonly class VerifyIntegrity
{
    public function __construct(
        private VerifyAndRecordLedger $ledger,
        private IntegrityChecks $checks,
        private ActivityLog $activityLog,
    ) {}

    /**
     * @return array{chain: array<string, mixed>, checks: list<array{id: string, title: string, passed: bool, findings: list<string>}>, passed: bool}
     */
    public function __invoke(): array
    {
        $chain = ($this->ledger)();
        $checks = $this->checks->run();
        $failing = count(array_filter($checks, fn (array $check): bool => ! $check['passed']));
        $passed = $chain['intact'] && $chain['matches_books'] && $chain['legacy']['intact'] && $failing === 0;

        $this->activityLog->system(
            'integrity.verified',
            $failing === 0
                ? __('Verified the controls of the books: all :count hold.', ['count' => count($checks)])
                : __('Verified the controls of the books: :failing of :count do not hold.', ['failing' => $failing, 'count' => count($checks)]),
            details: ['passed' => $failing === 0, 'checks' => array_map(fn (array $check): array => ['id' => $check['id'], 'passed' => $check['passed'], 'findings' => $check['findings']], $checks)],
        );

        return ['chain' => $chain, 'checks' => $checks, 'passed' => $passed];
    }
}
