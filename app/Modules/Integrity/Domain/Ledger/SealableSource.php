<?php

namespace App\Modules\Integrity\Domain\Ledger;

/**
 * Port: a module that closes periods tells the sealed ledger what each closed period contains.
 * Treasury implements it for weeks and Accounting for months; Integrity never reads their
 * tables.
 *
 * `build()` is called when sealing AND on every verification, and its result is compared with
 * what was sealed: that is how the ledger detects that the live books moved after a close.
 */
interface SealableSource
{
    /**
     * The block kind this source seals: 'weekly' or 'monthly'.
     */
    public function kind(): string;

    /**
     * What the closed period contains now, or null when the period has no close (any more).
     */
    public function build(string $periodKey): ?SealablePayload;

    /**
     * Keys of every closed period, oldest first: the ones that should have a block.
     *
     * @return list<string>
     */
    public function closedPeriods(): array;
}
