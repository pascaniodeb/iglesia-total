<?php

namespace App\Modules\Integrity\Application\Actions;

use App\Modules\Integrity\Application\Ledger\SealableSources;
use App\Modules\Integrity\Domain\Ledger\MerkleTree;
use App\Modules\Integrity\Infrastructure\Models\LedgerBlock;

/**
 * The Merkle proof that one record was inside a sealed period when it was closed: the leaf the live
 * books give for it, the sibling hashes up to the root, and whether that path reaches the root that
 * was SEALED (not a freshly computed one). If the record changed after the seal, its leaf is not
 * the sealed one and the path does not reach the sealed root: the proof says so instead of
 * pretending.
 *
 * Whoever holds the proof can check it without this system: hash the pair at each step, in order.
 */
final readonly class ProveRecord
{
    public function __construct(private SealableSources $sources) {}

    /**
     * @return array{
     *     status: 'valid'|'changed'|'missing'|'unsealed'|'unsupported',
     *     kind: string,
     *     period_key: string,
     *     record_key: string,
     *     height: int|null,
     *     block_hash: string|null,
     *     merkle_root: string|null,
     *     leaf: string|null,
     *     steps: list<array{side: string, hash: string}>
     * }
     */
    public function __invoke(string $kind, string $periodKey, string $recordKey): array
    {
        $result = ['kind' => $kind, 'period_key' => $periodKey, 'record_key' => $recordKey, 'height' => null, 'block_hash' => null, 'merkle_root' => null, 'leaf' => null, 'steps' => []];
        $block = LedgerBlock::query()->where('kind', $kind)->where('period_key', $periodKey)->first();

        if ($block === null) {
            return ['status' => 'unsealed', ...$result];
        }

        $result['height'] = $block->height;
        $result['block_hash'] = trim($block->hash);
        $result['merkle_root'] = trim($block->merkle_root);

        $rebuilt = $this->sources->for($kind)?->build($periodKey);

        if ($rebuilt === null || $rebuilt->leafKeys === []) {
            return ['status' => 'unsupported', ...$result];
        }

        $index = array_search($recordKey, $rebuilt->leafKeys, true);

        if ($index === false) {
            return ['status' => 'missing', ...$result];
        }

        $proof = MerkleTree::proof($rebuilt->leaves, $index);
        $result['leaf'] = $rebuilt->leaves[$index];
        $result['steps'] = array_map(fn ($step): array => $step->toArray(), $proof);

        return [
            'status' => MerkleTree::verify($rebuilt->leaves[$index], $proof, trim($block->merkle_root)) ? 'valid' : 'changed',
            ...$result,
        ];
    }
}
