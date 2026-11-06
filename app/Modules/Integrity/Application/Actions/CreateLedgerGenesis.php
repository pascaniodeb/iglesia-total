<?php

namespace App\Modules\Integrity\Application\Actions;

use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Domain\Ledger\LedgerException;
use App\Modules\Integrity\Domain\Ledger\SealablePayload;
use App\Modules\Integrity\Infrastructure\LedgerChain;
use App\Modules\Integrity\Infrastructure\Models\LedgerBlock;
use Illuminate\Support\Facades\DB;

/**
 * Opens the sealed ledger with its block zero.
 *
 * Its payload is fixed when it is created and never derived again: it says from when the books
 * are sealed and, at the cut-over, which chain it continues (the head of the old system's
 * chain), so the custody of the books passes from one chain to the other without a gap.
 */
final readonly class CreateLedgerGenesis
{
    public function __construct(private LedgerChain $chain, private ActivityLog $activityLog) {}

    /**
     * @param  array{height: int, hash: string}|null  $continues  head of the chain this one continues
     */
    public function __invoke(string $churchCode, string $firstPeriod, ?array $continues = null): LedgerBlock
    {
        return DB::transaction(function () use ($churchCode, $firstPeriod, $continues): LedgerBlock {
            $this->chain->lock();

            if ($this->chain->head() !== null) {
                throw new LedgerException('The sealed ledger already has its genesis.');
            }

            $block = $this->chain->append('genesis', null, new SealablePayload([
                'chain' => 'libro-sellado',
                'version' => 2,
                'churchCode' => $churchCode,
                'firstPeriod' => $firstPeriod,
                'continues' => $continues === null ? null : [
                    'chain' => 'libro-sellado',
                    'height' => $continues['height'],
                    'hash' => $continues['hash'],
                ],
            ]));

            $this->activityLog->record(
                'ledger.genesis',
                __('Opened the sealed ledger from :period.', ['period' => $firstPeriod]),
                $block,
                ['hash' => ['from' => null, 'to' => $block->hash]],
            );

            return $block;
        });
    }
}
