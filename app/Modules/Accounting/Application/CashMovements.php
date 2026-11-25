<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\Journal\CashMovement;
use App\Modules\Accounting\Domain\Journal\PostedEntry;
use Illuminate\Validation\ValidationException;

/**
 * Public contract for another module (Treasury: receipts) to move the church's cash through the
 * journal under the same rules as a movement recorded by hand:
 *
 * - the date is inside an open month and never in the future;
 * - an expense never takes more than the cash has in any currency (D4), checked after the write
 *   under each currency's lock, so two expenses at the same time cannot spend the same money;
 * - voiding is a reversal with a reason, dated like the original while its month is open and
 *   today once it is closed; voiding an income whose money was already spent is refused.
 *
 * It does not authorize: the calling use case checks the permission of its own operation. Call
 * it inside that use case's transaction, so the fact and its entry commit together.
 *
 * Refusals are ValidationExceptions keyed entry_date, account_code, amount_ves, amount_usd,
 * amount_cop (recording) and entry, reason (voiding); the caller maps them to its own fields.
 */
interface CashMovements
{
    /**
     * @throws ValidationException
     */
    public function record(CashMovement $movement): PostedEntry;

    /**
     * @throws ValidationException
     */
    public function reverse(string $entryId, string $reason): PostedEntry;
}
