<?php

namespace App\Modules\Integrity\Domain\Ledger;

use InvalidArgumentException;

/**
 * First pass of the verification: recompute every hash from the genesis and check that each
 * link points to its parent. It detects that someone rewrote a stored block. (The second pass,
 * rebuilding each payload from the live books, needs the modules' data and lives in the
 * application layer.)
 *
 * Same rules as the old system: heights start at 0 with no gaps, the genesis points to the
 * zero hash, the payload hash is the hash of the canonical payload and the block hash covers
 * height, parent, Merkle root and payload hash.
 */
final class ChainVerifier
{
    /**
     * @param  iterable<SealedBlock>  $blocks  in height order
     * @param  string  $genesisPrevHash  what the first block must point to (the zero hash, or
     *                                   the head of the chain it continues)
     * @return list<BlockIssue>
     */
    public static function verify(iterable $blocks, string $genesisPrevHash = Canonical::ZERO_HASH): array
    {
        $issues = [];
        $expectedHeight = null;
        $expectedPrev = $genesisPrevHash;

        foreach ($blocks as $block) {
            $expectedHeight ??= $block->height === 0 ? 0 : throw new InvalidArgumentException('The chain must be verified from its first block.');

            /** @param array<string, string|int> $params */
            $fail = function (string $message, array $params = []) use ($block, &$issues): void {
                $issues[] = new BlockIssue(BlockIssue::CHAIN, $block->height, $block->kind, $block->periodKey, $message, $params);
            };

            if ($block->height !== $expectedHeight) {
                $fail('Gap in the chain: expected height :expected, found :found.', ['expected' => $expectedHeight, 'found' => $block->height]);
            }

            if (! hash_equals($expectedPrev, $block->prevHash)) {
                $fail('The link does not point to the previous block (expected :expected…, found :found…).', [
                    'expected' => substr($expectedPrev, 0, 12),
                    'found' => substr($block->prevHash, 0, 12),
                ]);
            }

            if (! hash_equals(Canonical::hash($block->payload), $block->payloadHash)) {
                $fail('The sealed content does not match its own hash.');
            }

            $recomputed = BlockHash::of($block->height, $block->prevHash, $block->merkleRoot, $block->payloadHash);

            if (! hash_equals($recomputed, $block->hash)) {
                $fail('The block hash does not match its content.');
            }

            $expectedHeight = $block->height + 1;
            $expectedPrev = $block->hash;
        }

        return $issues;
    }
}
