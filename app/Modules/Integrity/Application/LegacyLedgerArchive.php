<?php

namespace App\Modules\Integrity\Application;

use App\Modules\Integrity\Domain\Ledger\BlockIssue;
use App\Modules\Integrity\Domain\Ledger\ChainVerifier;
use App\Modules\Integrity\Infrastructure\Models\LegacyLedgerBlock;

/**
 * The archive of the old system's sealed ledger: where its chain ends (the new chain's genesis
 * continues it) and whether it is still intact, verified with the same code as the new chain.
 */
final class LegacyLedgerArchive
{
    /**
     * @return array{height: int, hash: string}|null null while nothing was archived
     */
    public function head(): ?array
    {
        $head = LegacyLedgerBlock::query()->orderByDesc('height')->first();

        return $head === null ? null : ['height' => $head->height, 'hash' => $head->hash];
    }

    /**
     * First pass over the archive: every hash recomputed from the genesis and every link checked.
     *
     * @return array{blocks: int, height: int|null, head_hash: string|null, issues: list<BlockIssue>, intact: bool}
     */
    public function verify(): array
    {
        $blocks = LegacyLedgerBlock::query()->orderBy('height')->get();
        $issues = $blocks->isEmpty() ? [] : ChainVerifier::verify($blocks->map->toSealedBlock()->all());
        $head = $blocks->last();

        return [
            'blocks' => $blocks->count(),
            'height' => $head?->height,
            'head_hash' => $head?->hash,
            'issues' => $issues,
            'intact' => $issues === [],
        ];
    }
}
