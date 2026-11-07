<?php

namespace App\Modules\Integrity\Application\Actions;

use App\Modules\Integrity\Application\Ledger\SealableSources;
use App\Modules\Integrity\Domain\Ledger\LedgerException;
use App\Modules\Integrity\Infrastructure\Models\LedgerBlock;

/**
 * Seals every closed period that has no block yet, oldest first per kind (weeks before months,
 * as they close). A period that fails is reported and the rest go on: one stuck period must not
 * hold the others back.
 */
final readonly class SealPendingPeriods
{
    public function __construct(private SealableSources $sources, private SealPeriod $seal) {}

    /**
     * @return array{sealed: list<string>, failed: array<string, string>} "kind:period" keys; failures with their message
     */
    public function __invoke(): array
    {
        $sealed = [];
        $failed = [];

        foreach (['weekly', 'monthly'] as $kind) {
            $source = $this->sources->for($kind);

            if ($source === null) {
                continue;
            }

            $done = LedgerBlock::query()->where('kind', $kind)->pluck('period_key')->all();

            foreach ($source->closedPeriods() as $period) {
                if (in_array($period, $done, true)) {
                    continue;
                }

                try {
                    ($this->seal)($kind, $period);
                    $sealed[] = "{$kind}:{$period}";
                } catch (LedgerException $exception) {
                    $failed["{$kind}:{$period}"] = __($exception->getMessage(), $exception->params);
                }
            }
        }

        return ['sealed' => $sealed, 'failed' => $failed];
    }
}
