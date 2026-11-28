<?php

namespace App\Modules\Integrity\Infrastructure\Audit;

use App\Modules\Integrity\Application\Actions\VerifyLedgerChain;

/**
 * The verification of the sealed ledger, run once per request however many audit checks read it
 * (it rebuilds every sealed period, which is the slow part).
 */
final class SealedLedgerFindings
{
    /** @var array<string, mixed>|null */
    private ?array $result = null;

    public function __construct(private readonly VerifyLedgerChain $verify) {}

    /**
     * @return array<string, mixed>
     */
    public function result(): array
    {
        return $this->result ??= ($this->verify)();
    }
}
