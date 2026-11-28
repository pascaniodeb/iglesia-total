<?php

namespace App\Modules\Integrity\Application\Anchoring;

use App\Modules\Integrity\Domain\Anchoring\AnchorUnavailable;
use App\Modules\Integrity\Domain\Anchoring\ChainAnchor as Provider;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Integrity\Infrastructure\Models\ChainAnchor;
use App\Modules\Integrity\Infrastructure\Models\LedgerBlock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * External anchoring of the two chains: pins the head of each one with every enabled provider,
 * completes the pending proofs and checks, with no network, that what was anchored still matches
 * the chain.
 *
 * The head summarises all the history before it (every hash includes the previous one): anchoring
 * it proves that the whole chain existed like this at that moment.
 */
final readonly class ChainAnchoring
{
    public const array CHAINS = ['ledger', 'activity'];

    /**
     * @param  list<Provider>  $providers
     */
    public function __construct(private array $providers) {}

    /**
     * Pins the head of each chain with every provider that does not have it yet.
     *
     * @return array{anchored: list<ChainAnchor>, failed: list<string>}
     */
    public function anchorHeads(): array
    {
        $result = ['anchored' => [], 'failed' => []];

        foreach (self::CHAINS as $chain) {
            $head = $this->head($chain);

            if ($head === null) {
                continue;
            }

            foreach ($this->providers as $provider) {
                if (ChainAnchor::query()->where('chain', $chain)->where('provider', $provider->provider())->where('position', '>=', $head['position'])->exists()) {
                    continue;
                }

                $statement = ChainAnchor::statementFor($chain, $head['position'], $head['hash']);

                try {
                    $proof = $provider->submit(hash('sha256', $statement, true));
                } catch (AnchorUnavailable $exception) {
                    Log::error('The chain could not be anchored.', ['chain' => $chain, 'provider' => $provider->provider(), 'error' => $exception->getMessage()]);
                    $result['failed'][] = "{$chain}:{$provider->provider()}";

                    continue;
                }

                $result['anchored'][] = ChainAnchor::query()->create([
                    'chain' => $chain,
                    'provider' => $provider->provider(),
                    'position' => $head['position'],
                    'hash' => $head['hash'],
                    'statement' => $statement,
                    'digest' => hash('sha256', $statement),
                    'status' => $proof->confirmed ? 'confirmed' : 'pending',
                    'proof' => $proof->data,
                    'proven_at' => $proof->provenAt,
                    'confirmed_at' => $proof->confirmed ? now() : null,
                ])->refresh();
            }
        }

        return $result;
    }

    /**
     * Completes the pending proofs that are ready.
     *
     * @return list<ChainAnchor> those that became confirmed
     */
    public function upgradePending(): array
    {
        $confirmed = [];

        foreach (ChainAnchor::query()->where('status', 'pending')->orderBy('position')->get() as $anchor) {
            $provider = $this->provider($anchor->provider);
            $proof = $provider?->upgrade($anchor->digestBytes(), $anchor->toProof());

            if ($proof === null || ! $provider->verifyOffline($anchor->digestBytes(), $proof)) {
                continue;
            }

            $anchor->update(['status' => 'confirmed', 'proof' => $proof->data, 'proven_at' => $proof->provenAt, 'confirmed_at' => now()]);
            $confirmed[] = $anchor;
        }

        return $confirmed;
    }

    /**
     * Offline verification:
     *
     * - rewritten: the anchored block is no longer in its chain with that hash (history rewritten).
     * - invalid: a confirmed proof does not correspond to its statement.
     *
     * @return array{anchors: int, confirmed: int, pending: int, rewritten: list<string>, invalid: list<string>, latest: array<string, CarbonImmutable>}
     */
    public function verify(): array
    {
        $result = ['anchors' => 0, 'confirmed' => 0, 'pending' => 0, 'rewritten' => [], 'invalid' => [], 'latest' => []];

        foreach (ChainAnchor::query()->orderBy('chain')->orderBy('position')->cursor() as $anchor) {
            $result['anchors']++;
            $result[$anchor->status === 'confirmed' ? 'confirmed' : 'pending']++;
            $label = "{$anchor->chain}:{$anchor->provider}:{$anchor->position}";

            if (! $this->linked($anchor)) {
                $result['rewritten'][] = $label;
            }

            if ($anchor->status === 'confirmed') {
                if (! $this->proves($anchor)) {
                    $result['invalid'][] = $label;
                }

                $result['latest'][$anchor->chain] = CarbonImmutable::instance($anchor->confirmed_at ?? $anchor->created_at);
            }
        }

        return $result;
    }

    /**
     * @return array{position: int, hash: string}|null
     */
    private function head(string $chain): ?array
    {
        if ($chain === 'ledger') {
            $block = LedgerBlock::query()->orderByDesc('height')->first(['height', 'hash']);

            return $block === null ? null : ['position' => $block->height, 'hash' => trim($block->hash)];
        }

        $entry = ActivityLogEntry::query()->orderByDesc('seq')->first(['seq', 'hash']);

        return $entry === null ? null : ['position' => $entry->seq, 'hash' => trim($entry->hash)];
    }

    private function linked(ChainAnchor $anchor): bool
    {
        return $anchor->chain === 'ledger'
            ? LedgerBlock::query()->where('height', $anchor->position)->where('hash', $anchor->hash)->exists()
            : ActivityLogEntry::query()->where('seq', $anchor->position)->where('hash', $anchor->hash)->exists();
    }

    private function proves(ChainAnchor $anchor): bool
    {
        $provider = $this->provider($anchor->provider);

        if ($provider === null || $anchor->statement !== ChainAnchor::statementFor($anchor->chain, $anchor->position, trim($anchor->hash))) {
            return false;
        }

        try {
            return $provider->verifyOffline($anchor->digestBytes(), $anchor->toProof());
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private function provider(string $name): ?Provider
    {
        foreach ($this->providers as $provider) {
            if ($provider->provider() === $name) {
                return $provider;
            }
        }

        return null;
    }
}
