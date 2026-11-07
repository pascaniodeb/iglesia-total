<?php

namespace App\Modules\Integrity\Infrastructure;

use App\Modules\Integrity\Application\SealedPeriods;
use App\Modules\Integrity\Infrastructure\Models\LedgerBlock;

final class DatabaseSealedPeriods implements SealedPeriods
{
    public function of(string $kind, array $periodKeys): array
    {
        if ($periodKeys === []) {
            return [];
        }

        return LedgerBlock::query()
            ->where('kind', $kind)
            ->whereIn('period_key', $periodKeys)
            ->get(['period_key', 'height', 'hash'])
            ->mapWithKeys(fn (LedgerBlock $block) => [(string) $block->period_key => ['height' => $block->height, 'hash' => $block->hash]])
            ->all();
    }
}
