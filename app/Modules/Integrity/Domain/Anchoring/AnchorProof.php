<?php

namespace App\Modules\Integrity\Domain\Anchoring;

use Carbon\CarbonImmutable;

/**
 * The proof of an anchor as it is stored: confirmed or pending, the provider's own data (binaries
 * in base64) and, when the provider gives it, the time it certifies.
 */
final readonly class AnchorProof
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public bool $confirmed, public array $data, public ?CarbonImmutable $provenAt = null) {}
}
