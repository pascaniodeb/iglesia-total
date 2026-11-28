<?php

namespace App\Modules\Integrity\Infrastructure\Models;

use App\Modules\Integrity\Domain\Anchoring\AnchorProof;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An external anchor of the head of a chain ('ledger' or 'activity'). It only goes from pending to
 * confirmed (PostgreSQL guarantees it) and is never deleted.
 *
 * @property string $id UUIDv7
 * @property string $chain
 * @property string $provider
 * @property int $position height of the ledger block or sequence of the activity entry
 * @property string $hash
 * @property string $statement
 * @property string $digest
 * @property string $status
 * @property array<string, mixed> $proof
 * @property Carbon|null $proven_at
 * @property Carbon|null $confirmed_at
 * @property Carbon $created_at
 */
class ChainAnchor extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * The statement that is anchored: plain text, checkable with the official tools.
     */
    public static function statementFor(string $chain, int $position, string $hash): string
    {
        $label = $chain === 'ledger' ? 'sealed ledger (height)' : 'activity log (seq)';

        return "Iglesia Total {$label}\nposition: {$position}\nhash: {$hash}\n";
    }

    public function digestBytes(): string
    {
        return (string) hex2bin(trim($this->digest));
    }

    public function toProof(): AnchorProof
    {
        return new AnchorProof($this->status === 'confirmed', $this->proof, $this->proven_at === null ? null : CarbonImmutable::instance($this->proven_at));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['position' => 'integer', 'proof' => 'array', 'proven_at' => 'datetime', 'confirmed_at' => 'datetime'];
    }
}
