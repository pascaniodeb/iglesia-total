<?php

namespace App\Modules\Integrity\Domain\Ledger;

use InvalidArgumentException;
use stdClass;

/**
 * The facts a block seals, as a module derives them from its books: the canonical payload
 * (totals, counts, keys; amounts as decimal text) and one hashed leaf per sealed record, in a
 * stable order (by id, never by date or amount).
 *
 * It must be deterministic and carry no execution timestamp: it is rebuilt on every
 * verification and compared with what was sealed, and a payload that changes between two runs
 * would declare every closed period broken without anyone touching a cent.
 */
final readonly class SealablePayload
{
    public string $merkleRoot;

    public string $payloadHash;

    /**
     * @param  array<array-key, mixed>|stdClass  $payload  an object: associative array or stdClass
     * @param  list<string>  $leaves  hex SHA-256 of each record (use Canonical::hash())
     */
    public function __construct(public array|stdClass $payload, public array $leaves = [])
    {
        if (is_array($payload) && array_is_list($payload)) {
            throw new InvalidArgumentException('A sealed payload is an object, not a list.');
        }

        foreach ($leaves as $leaf) {
            if (preg_match('/^[0-9a-f]{64}$/', $leaf) !== 1) {
                throw new InvalidArgumentException('Every Merkle leaf is a hex SHA-256.');
            }
        }

        $this->merkleRoot = MerkleTree::root($leaves);
        $this->payloadHash = Canonical::hash($payload);
    }
}
