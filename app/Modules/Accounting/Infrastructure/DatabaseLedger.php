<?php

namespace App\Modules\Accounting\Infrastructure;

use App\Modules\Accounting\Application\Actions\PostJournalEntry;
use App\Modules\Accounting\Application\Actions\ReverseJournalEntry;
use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Domain\Journal\JournalEntryDraft;
use App\Modules\Accounting\Domain\Journal\LedgerConflict;
use App\Modules\Accounting\Domain\Journal\PostedEntry;
use App\Modules\Accounting\Infrastructure\Models\JournalEntry;

/**
 * The Ledger contract backed by the PostgreSQL journal.
 */
final readonly class DatabaseLedger implements Ledger
{
    public function __construct(private PostJournalEntry $post, private ReverseJournalEntry $reverse) {}

    public function post(JournalEntryDraft $draft): PostedEntry
    {
        return self::posted(($this->post)($draft));
    }

    public function reverse(string $entryId, string $reason, ?string $entryDate = null): PostedEntry
    {
        $original = JournalEntry::query()->find($entryId)
            ?? throw new LedgerConflict('There is no journal entry :id.', ['id' => $entryId]);

        return self::posted(($this->reverse)($original, $reason, $entryDate));
    }

    private static function posted(JournalEntry $entry): PostedEntry
    {
        return new PostedEntry($entry->id, $entry->entry_date->toDateString(), $entry->month, ! $entry->wasRecentlyCreated);
    }
}
