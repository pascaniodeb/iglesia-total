<?php

namespace App\Modules\Integrity\Infrastructure\Models;

use App\Models\User;
use App\Modules\Integrity\Domain\Ledger\SealedBlock;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A block of the sealed ledger. Insert-only; the database checks its height, link and hash.
 *
 * @property string $id UUIDv7
 * @property int $height
 * @property string $kind
 * @property string|null $period_key
 * @property string $prev_hash
 * @property string $merkle_root
 * @property string $payload_hash
 * @property string $hash
 * @property int $leaves_count
 * @property Carbon $sealed_at
 * @property string|null $sealed_by
 * @property-read User|null $sealer
 */
#[Fillable(['height', 'kind', 'period_key', 'prev_hash', 'merkle_root', 'payload_hash', 'hash', 'payload', 'leaves_count', 'sealed_by'])]
class LedgerBlock extends Model
{
    use HasUuids;

    public $timestamps = false;

    /**
     * @return BelongsTo<User, $this>
     */
    public function sealer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sealed_by');
    }

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
            'height' => 'integer',
            'leaves_count' => 'integer',
            'sealed_at' => 'immutable_datetime',
        ];
    }
}
