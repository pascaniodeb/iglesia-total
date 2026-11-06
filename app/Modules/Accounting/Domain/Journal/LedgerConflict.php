<?php

namespace App\Modules\Accounting\Domain\Journal;

use RuntimeException;

/**
 * A posting the ledger refuses: an origin already posted with different content, an unknown or
 * inactive account, a reversal of a reversal, an entry already reversed. The message is an
 * English sentence that is also its translation key.
 */
final class LedgerConflict extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $params
     */
    public function __construct(string $message, public readonly array $params = [])
    {
        parent::__construct($message);
    }
}
