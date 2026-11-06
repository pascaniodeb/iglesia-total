<?php

namespace App\Modules\Integrity\Infrastructure;

use App\Modules\Integrity\Domain\Ledger\BlockHash;
use App\Modules\Integrity\Domain\Ledger\Canonical;
use App\Modules\Integrity\Domain\Ledger\SealablePayload;
use App\Modules\Integrity\Infrastructure\Models\LedgerBlock;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Persistence of the sealed ledger: appends a block on top of the head. Must run inside a
 * transaction; it takes the same advisory lock as the database trigger, so two sealings at
 * the same time queue up instead of racing for the same height. The signed-in user, if any, is
 * recorded as who sealed.
 */
final readonly class LedgerChain
{
    public function __construct(private AuthFactory $auth) {}

    public function lock(): void
    {
        DB::select("SELECT pg_advisory_xact_lock(hashtext('ledger_blocks_chain'))");
    }

    public function head(): ?LedgerBlock
    {
        return LedgerBlock::query()->orderByDesc('height')->first();
    }

    public function append(string $kind, ?string $periodKey, SealablePayload $sealable): LedgerBlock
    {
        $head = $this->head();
        $height = $head === null ? 0 : $head->height + 1;
        $prevHash = $head === null ? Canonical::ZERO_HASH : $head->hash;
        $sealedBy = $this->auth->guard()->id();

        $block = LedgerBlock::query()->create([
            'height' => $height,
            'kind' => $kind,
            'period_key' => $periodKey,
            'prev_hash' => $prevHash,
            'merkle_root' => $sealable->merkleRoot,
            'payload_hash' => $sealable->payloadHash,
            'hash' => BlockHash::of($height, $prevHash, $sealable->merkleRoot, $sealable->payloadHash),
            'payload' => json_encode($sealable->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'leaves_count' => count($sealable->leaves),
            'sealed_by' => $sealedBy === null ? null : (string) $sealedBy,
        ]);

        $stored = LedgerBlock::query()->findOrFail($block->id);

        // What PostgreSQL kept must hash to what was sealed; otherwise roll the block back.
        if (! hash_equals($sealable->payloadHash, Canonical::hash($stored->payload()))) {
            throw new LogicException('The stored payload does not reproduce the sealed hash.');
        }

        return $stored;
    }
}
