<?php

namespace App\Modules\Integrity\Domain\Ledger;

use InvalidArgumentException;

/**
 * Merkle tree over the already-hashed records a block seals (one leaf per offering or entry).
 *
 * It lets an auditor check that ONE offering is inside a sealed week by receiving only its
 * branch (log2(n) hashes) instead of the whole week with names and amounts.
 *
 * Byte-exact port of the old system: an odd level duplicates its last leaf (Bitcoin's
 * convention), parents are sha256(left.right) over the hex strings, and zero leaves give the
 * zero hash (a week without offerings is sealed too: sealing nothing says it was reviewed).
 */
final class MerkleTree
{
    /**
     * @param  list<string>  $leaves
     */
    public static function root(array $leaves): string
    {
        if ($leaves === []) {
            return Canonical::ZERO_HASH;
        }

        $level = $leaves;

        while (count($level) > 1) {
            $level = self::parents($level);
        }

        return $level[0];
    }

    /**
     * The sibling hashes from the leaf up to the root, each with its side.
     *
     * @param  list<string>  $leaves
     * @return list<MerkleStep>
     */
    public static function proof(array $leaves, int $index): array
    {
        if ($index < 0 || $index >= count($leaves)) {
            throw new InvalidArgumentException("Index outside the tree: {$index}.");
        }

        $proof = [];
        $level = $leaves;
        $position = $index;

        while (count($level) > 1) {
            $isRight = $position % 2 === 1;
            $sibling = $level[$isRight ? $position - 1 : $position + 1] ?? $level[$position];
            $proof[] = new MerkleStep($isRight ? MerkleSide::Left : MerkleSide::Right, $sibling);

            $level = self::parents($level);
            $position = intdiv($position, 2);
        }

        return $proof;
    }

    /**
     * Recomputes the path from the leaf to the root. This is what an auditor runs.
     *
     * @param  list<MerkleStep>  $proof
     */
    public static function verify(string $leaf, array $proof, string $root): bool
    {
        $current = $leaf;

        foreach ($proof as $step) {
            $current = $step->side === MerkleSide::Left
                ? Canonical::sha256($step->hash.$current)
                : Canonical::sha256($current.$step->hash);
        }

        return hash_equals($root, $current);
    }

    /**
     * @param  list<string>  $level
     * @return list<string>
     */
    private static function parents(array $level): array
    {
        $next = [];

        for ($i = 0, $count = count($level); $i < $count; $i += 2) {
            $next[] = Canonical::sha256($level[$i].($level[$i + 1] ?? $level[$i]));
        }

        return $next;
    }
}
