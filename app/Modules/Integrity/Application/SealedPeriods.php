<?php

namespace App\Modules\Integrity\Application;

/**
 * Public contract of the sealed ledger for the modules that close periods: which of their
 * periods are sealed (block height and hash), so their screens can show it.
 */
interface SealedPeriods
{
    /**
     * @param  list<string>  $periodKeys
     * @return array<string, array{height: int, hash: string}> by period key; unsealed periods are left out
     */
    public function of(string $kind, array $periodKeys): array;
}
