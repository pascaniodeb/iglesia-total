<?php

namespace App\Modules\Accounting\Domain\Journal;

/**
 * What another module gets back after posting: enough to link its record to the entry.
 */
final readonly class PostedEntry
{
    public function __construct(
        public string $id,
        public string $entryDate,
        public string $month,
        public bool $wasAlreadyPosted,
    ) {}
}
