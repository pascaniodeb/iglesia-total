<?php

namespace App\Modules\Integrity\Application\Actions;

use App\Modules\Integrity\Application\Ledger\SealableSources;
use App\Modules\Integrity\Domain\Ledger\BlockIssue;
use App\Modules\Integrity\Domain\Ledger\Canonical;
use App\Modules\Integrity\Domain\Ledger\ChainVerifier;
use App\Modules\Integrity\Infrastructure\Models\LedgerBlock;
use stdClass;

/**
 * The two passes of the sealed ledger verification.
 *
 * First: recompute every hash from the genesis and check every link (ChainVerifier). It
 * detects that someone rewrote a block: a security incident.
 *
 * Second: rebuild each sealed period's payload from the LIVE books (the module's source) and
 * compare it with what was sealed. It detects that data moved after the close, which is what
 * really happens in a system with people in it: nobody edits the block table, but someone
 * records an offering with an old date.
 *
 * It also lists the closed periods that have no block yet ("seal pending").
 */
final readonly class VerifyLedgerChain
{
    public function __construct(private SealableSources $sources) {}

    /**
     * @return array{
     *     blocks: int,
     *     height: int|null,
     *     head_hash: string|null,
     *     issues: list<BlockIssue>,
     *     intact: bool,
     *     matches_books: bool,
     *     unsealed: list<array{kind: string, period_key: string}>
     * }
     */
    public function __invoke(): array
    {
        $blocks = LedgerBlock::query()->orderBy('height')->get();
        $sealed = $blocks->map->toSealedBlock()->all();

        $chainIssues = $sealed === [] ? [] : ChainVerifier::verify($sealed);
        $driftIssues = [];

        foreach ($sealed as $block) {
            if ($block->kind === 'genesis' || $block->periodKey === null) {
                continue;
            }

            $source = $this->sources->for($block->kind);
            $rebuilt = $source?->build($block->periodKey);

            $message = match (true) {
                $source === null => 'Nothing can rebuild periods of kind :kind to compare them with the books.',
                $rebuilt === null => 'The period :period is sealed but its close no longer exists in the books.',
                $rebuilt->payloadHash !== $block->payloadHash || $rebuilt->merkleRoot !== $block->merkleRoot => 'The books no longer match what was sealed for :period (:fields).',
                default => null,
            };

            if ($message !== null) {
                $driftIssues[] = new BlockIssue(BlockIssue::DRIFT, $block->height, $block->kind, $block->periodKey, $message, [
                    'kind' => $block->kind,
                    'period' => $block->periodKey,
                    'fields' => $rebuilt === null ? '' : $this->changedFields($block->payload, $rebuilt->payload, $rebuilt->merkleRoot !== $block->merkleRoot),
                ]);
            }
        }

        $head = $blocks->last();

        return [
            'blocks' => $blocks->count(),
            'height' => $head?->height,
            'head_hash' => $head?->hash,
            'issues' => [...$chainIssues, ...$driftIssues],
            'intact' => $chainIssues === [],
            'matches_books' => $chainIssues === [] && $driftIssues === [],
            'unsealed' => $this->unsealed($blocks->map(fn (LedgerBlock $block): string => "{$block->kind}:{$block->period_key}")->values()->all()),
        ];
    }

    /**
     * Top-level payload keys whose canonical value differs, plus the records when the Merkle
     * root moved: enough to know where to look without dumping the payload.
     *
     * @param  array<array-key, mixed>|stdClass  $rebuilt
     */
    private function changedFields(mixed $sealed, array|stdClass $rebuilt, bool $recordsChanged): string
    {
        $before = $sealed instanceof stdClass ? get_object_vars($sealed) : [];
        $after = $rebuilt instanceof stdClass ? get_object_vars($rebuilt) : $rebuilt;

        $keys = array_values(array_unique(array_map(strval(...), [...array_keys($before), ...array_keys($after)])));
        sort($keys);

        $changed = array_values(array_filter(
            $keys,
            fn (string $key): bool => Canonical::serialize($before[$key] ?? null) !== Canonical::serialize($after[$key] ?? null),
        ));

        if ($recordsChanged) {
            $changed[] = 'records';
        }

        return implode(', ', $changed);
    }

    /**
     * Closed periods without a block, oldest first per kind.
     *
     * @param  array<int, string>  $sealedKeys  "kind:period" of every block (only membership matters)
     * @return list<array{kind: string, period_key: string}>
     */
    private function unsealed(array $sealedKeys): array
    {
        $pending = [];

        foreach ($this->sources->all() as $kind => $source) {
            foreach ($source->closedPeriods() as $period) {
                if (! in_array("{$kind}:{$period}", $sealedKeys, true)) {
                    $pending[] = ['kind' => $kind, 'period_key' => $period];
                }
            }
        }

        return $pending;
    }
}
