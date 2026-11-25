<?php

namespace App\Modules\Accounting\Application;

/**
 * Port declared by Accounting and implemented by Treasury: the fingerprint of the receipt behind a
 * journal entry, so the monthly seal binds what the paper says (holder, payment, signers...) to the
 * entry without Accounting reading Treasury's tables.
 *
 * The fingerprint covers what was issued and never its later status: voiding a receipt of a closed
 * month is recorded forward, by a reversal in an open month, and must not move that month's seal.
 */
interface ReceiptHashes
{
    /**
     * @param  list<string>  $entryIds
     * @return array<string, string> the hash of the receipt each entry posts, by entry id (entries without a receipt are left out)
     */
    public function byEntry(array $entryIds): array;
}
