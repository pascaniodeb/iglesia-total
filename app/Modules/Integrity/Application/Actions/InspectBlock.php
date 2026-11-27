<?php

namespace App\Modules\Integrity\Application\Actions;

use App\Modules\Integrity\Application\Ledger\SealableSources;
use App\Modules\Integrity\Domain\Ledger\BlockHash;
use App\Modules\Integrity\Domain\Ledger\Canonical;
use App\Modules\Integrity\Infrastructure\Models\LedgerBlock;

/**
 * Everything the center shows of one block: its own fields, whether its hash recomputes from them,
 * the payload that was sealed, whether the live books still give the same payload and root, and
 * the sealed records (key and leaf hash) so a proof can be asked for any of them.
 */
final readonly class InspectBlock
{
    /** Records listed per block; the count says how many there are. */
    public const int LEAVES_SHOWN = 200;

    public function __construct(private SealableSources $sources) {}

    /**
     * @return array{
     *     block: array<string, mixed>,
     *     hash_recomputes: bool,
     *     payload_recomputes: bool,
     *     books: 'matches'|'drifted'|'unavailable',
     *     payload: mixed,
     *     leaves: list<array{key: string, leaf: string}>,
     *     leaves_total: int,
     *     proofs_offered: bool
     * }|null
     */
    public function __invoke(int $height): ?array
    {
        $block = LedgerBlock::query()->where('height', $height)->first();

        if ($block === null) {
            return null;
        }

        $payload = $block->payload();
        $rebuilt = $block->period_key === null ? null : $this->sources->for($block->kind)?->build($block->period_key);

        $leaves = [];

        if ($rebuilt !== null && $rebuilt->leafKeys !== []) {
            foreach (array_slice($rebuilt->leafKeys, 0, self::LEAVES_SHOWN, true) as $index => $key) {
                $leaves[] = ['key' => $key, 'leaf' => $rebuilt->leaves[$index]];
            }
        }

        return [
            'block' => [
                'height' => $block->height,
                'kind' => $block->kind,
                'period_key' => $block->period_key,
                'prev_hash' => trim($block->prev_hash),
                'merkle_root' => trim($block->merkle_root),
                'payload_hash' => trim($block->payload_hash),
                'hash' => trim($block->hash),
                'leaves_count' => $block->leaves_count,
                'sealed_at' => $block->sealed_at->toIso8601String(),
                'sealed_by' => $block->sealer?->name,
            ],
            'hash_recomputes' => BlockHash::of($block->height, trim($block->prev_hash), trim($block->merkle_root), trim($block->payload_hash)) === trim($block->hash),
            'payload_recomputes' => Canonical::hash($payload) === trim($block->payload_hash),
            'books' => match (true) {
                $block->kind === 'genesis' => 'matches',
                $rebuilt === null => 'unavailable',
                $rebuilt->payloadHash === trim($block->payload_hash) && $rebuilt->merkleRoot === trim($block->merkle_root) => 'matches',
                default => 'drifted',
            },
            'payload' => $payload,
            'leaves' => $leaves,
            'leaves_total' => $rebuilt === null ? 0 : count($rebuilt->leaves),
            'proofs_offered' => $rebuilt !== null && $rebuilt->leafKeys !== [],
        ];
    }
}
