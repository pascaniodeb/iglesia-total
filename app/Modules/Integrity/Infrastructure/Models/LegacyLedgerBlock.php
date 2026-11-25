<?php

namespace App\Modules\Integrity\Infrastructure\Models;

use App\Modules\Integrity\Domain\Ledger\SealedBlock;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A block of the old system's sealed ledger, archived verbatim. Insert-only; the database checks
 * its height, link and hash like the new chain's.
 *
 * @property string $id UUIDv7
 * @property int $legacy_id
 * @property int $height
 * @property string $kind
 * @property string|null $period_key
 * @property string $prev_hash
 * @property string $merkle_root
 * @property string $payload_hash
 * @property string $hash
 * @property Carbon $sealed_at
 * @property int|null $legacy_sealed_by
 * @property string|null $anchor_key
 * @property Carbon $archived_at
 */
class LegacyLedgerBlock extends Model
{
    use HasUuids;

    public $timestamps = false;

    /**
     * The payload exactly as stored: JSON objects decoded as stdClass, so the canonical
     * serialization (and its hash) is the one that was sealed.
     */
    public function payload(): mixed
    {
        return json_decode((string) $this->getRawOriginal('payload'), false, 512, JSON_THROW_ON_ERROR);
    }

    public function toSealedBlock(): SealedBlock
    {
        return new SealedBlock(
            height: $this->height,
            kind: $this->kind,
            periodKey: $this->period_key,
            prevHash: $this->prev_hash,
            merkleRoot: $this->merkle_root,
            payloadHash: $this->payload_hash,
            hash: $this->hash,
            payload: $this->payload(),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'legacy_id' => 'integer',
            'height' => 'integer',
            'legacy_sealed_by' => 'integer',
            'sealed_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }
}
