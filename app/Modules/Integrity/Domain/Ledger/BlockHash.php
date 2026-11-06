<?php

namespace App\Modules\Integrity\Domain\Ledger;

/**
 * The hash of a sealed block: SHA-256 of height, prev_hash, merkle_root and payload_hash joined
 * by a NUL byte ("\0"), exactly as the old system computed it. (The NUL is invisible in most
 * editors and terminals, where it looks like a space: the golden tests pin it.)
 *
 * The height is part of the hash on purpose: without it two blocks with the same content and
 * the same parent would be indistinguishable and the chain could be reordered without breaking
 * a link.
 */
final class BlockHash
{
    public static function of(int $height, string $prevHash, string $merkleRoot, string $payloadHash): string
    {
        return Canonical::sha256(implode("\0", [(string) $height, $prevHash, $merkleRoot, $payloadHash]));
    }
}
