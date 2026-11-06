<?php

namespace App\Modules\Integrity\Domain\Ledger;

/**
 * On which side of the current hash a sibling goes when a Merkle proof is recomputed.
 */
enum MerkleSide: string
{
    case Left = 'left';
    case Right = 'right';
}
