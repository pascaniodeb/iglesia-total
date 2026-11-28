<?php

namespace App\Modules\Integrity\Domain\Anchoring;

/**
 * Bitcoin headers: the Merkle root of a block by height, in hex as block explorers show it
 * (reversed relative to the bytes of the header).
 */
interface BitcoinHeaders
{
    public function merkleRootAt(int $height): ?string;
}
