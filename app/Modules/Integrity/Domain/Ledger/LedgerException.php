<?php

namespace App\Modules\Integrity\Domain\Ledger;

use RuntimeException;

/**
 * A sealing request the ledger refuses (no genesis yet, a period without a close, no source for
 * a kind). The message is an English sentence that is also its translation key.
 */
final class LedgerException extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $params
     */
    public function __construct(string $message, public readonly array $params = [])
    {
        parent::__construct($message);
    }
}
