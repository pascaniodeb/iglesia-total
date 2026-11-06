<?php

namespace App\Modules\Integrity\Domain\Ledger;

/**
 * A block of the sealed ledger as it is stored: what the verifier recomputes and compares.
 * `$payload` is the decoded JSON (objects as stdClass), exactly as it was sealed.
 */
final readonly class SealedBlock
{
    public function __construct(
        public int $height,
        public string $kind,
        public ?string $periodKey,
        public string $prevHash,
        public string $merkleRoot,
        public string $payloadHash,
        public string $hash,
        public mixed $payload,
    ) {}
}
