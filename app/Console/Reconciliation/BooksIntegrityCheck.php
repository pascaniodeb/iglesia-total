<?php

namespace App\Console\Reconciliation;

use App\Modules\Integrity\Domain\IntegrityCheck;

/**
 * One of the cross-checks between the treasury and the accounting books, as a check of the
 * integrity center. It lives here, with BooksVerification, because it reads Treasury and
 * Accounting side by side, which Integrity may not.
 */
final class BooksIntegrityCheck implements IntegrityCheck
{
    public function __construct(private readonly BooksVerification $books, private readonly string $key) {}

    public function id(): string
    {
        return "books.{$this->key}";
    }

    public function title(): string
    {
        return BooksVerification::title($this->key);
    }

    public function findings(): array
    {
        return $this->books->findingsOf($this->key);
    }
}
