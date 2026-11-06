<?php

namespace App\Modules\Integrity\Application\Actions;

use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Application\Ledger\SealableSources;
use App\Modules\Integrity\Domain\Ledger\LedgerException;
use App\Modules\Integrity\Infrastructure\LedgerChain;
use App\Modules\Integrity\Infrastructure\Models\LedgerBlock;
use Illuminate\Support\Facades\DB;

/**
 * Seals a closed period (a week or a month) as the next block of the chain.
 *
 * Idempotent: a period that already has its block returns that block, so a "seal pending"
 * retry after a failure can never seal twice. The module that closes the period calls this
 * after its own close is committed; a failure here never undoes the close, it leaves the period
 * pending a seal.
 */
final readonly class SealPeriod
{
    public function __construct(
        private SealableSources $sources,
        private LedgerChain $chain,
        private ActivityLog $activityLog,
    ) {}

    /**
     * @throws LedgerException
     */
    public function __invoke(string $kind, string $periodKey): LedgerBlock
    {
        $source = $this->sources->for($kind)
            ?? throw new LedgerException('Nothing can seal periods of kind :kind.', ['kind' => $kind]);

        return DB::transaction(function () use ($source, $kind, $periodKey): LedgerBlock {
            $this->chain->lock();

            $existing = LedgerBlock::query()->where('kind', $kind)->where('period_key', $periodKey)->first();

            if ($existing !== null) {
                return $existing;
            }

            if ($this->chain->head() === null) {
                throw new LedgerException('The sealed ledger has no genesis yet.');
            }

            $sealable = $source->build($periodKey)
                ?? throw new LedgerException('The period :period is not closed.', ['period' => $periodKey]);

            $block = $this->chain->append($kind, $periodKey, $sealable);

            $this->activityLog->record(
                'ledger.sealed',
                __('Sealed :period in the sealed ledger (block :height).', ['period' => $periodKey, 'height' => $block->height]),
                $block,
                ['hash' => ['from' => null, 'to' => $block->hash]],
            );

            return $block;
        });
    }
}
