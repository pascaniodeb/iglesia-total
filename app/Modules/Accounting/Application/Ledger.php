<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\Journal\JournalEntryDraft;
use App\Modules\Accounting\Domain\Journal\LedgerConflict;
use App\Modules\Accounting\Domain\Journal\PostedEntry;

/**
 * Public contract of the journal: the only way another module (Treasury) writes to the books.
 *
 * - Idempotent by origin: posting the same fact again returns the entry already posted; posting
 *   it with different content is a LedgerConflict (the books never change silently).
 * - Corrections are reversals with a reason; nothing is edited or deleted.
 * - It does not authorize: the calling use case checks the permission of its own operation.
 *   Call it inside that use case's transaction, so the fact and its entry commit together.
 */
interface Ledger
{
    /**
     * @throws LedgerConflict
     */
    public function post(JournalEntryDraft $draft): PostedEntry;

    /**
     * @throws LedgerConflict
     */
    public function reverse(string $entryId, string $reason, ?string $entryDate = null): PostedEntry;
}
