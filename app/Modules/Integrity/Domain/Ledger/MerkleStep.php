<?php

namespace App\Modules\Integrity\Domain\Ledger;

/**
 * One step of a Merkle proof: the sibling hash and the side it goes on. Without the side the
 * proof cannot be recomputed: sha256(a.b) is not sha256(b.a).
 */
final readonly class MerkleStep
{
    public function __construct(public MerkleSide $side, public string $hash) {}

    /**
     * @return array{side: string, hash: string}
     */
    public function toArray(): array
    {
        return ['side' => $this->side->value, 'hash' => $this->hash];
    }
}
