<?php

namespace App\Modules\Accounting\Application\Actions;

use App\Modules\Accounting\Application\CashMovements;
use App\Modules\Accounting\Infrastructure\Models\JournalEntry;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a movement recorded by hand with a reversal and its reason (nothing is edited or
 * deleted). The reversal is dated like the original while its month is open; once that month
 * is closed, today (D7: a closed month never changes). Reversing an income takes money out of
 * the cash, so D4 applies: the rules live in CashMovements::reverse, shared with receipts.
 *
 * Automatic entries (offerings, receipts) are corrected where they came from, not here.
 */
final readonly class VoidMovement
{
    public const array MANUAL_ORIGINS = ['manual_movement', 'currency_exchange'];

    public function __construct(private CashMovements $movements) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(JournalEntry $entry, string $reason): JournalEntry
    {
        if (! in_array($entry->origin_type, self::MANUAL_ORIGINS, true) || $entry->reversal_of !== null) {
            throw ValidationException::withMessages(['entry' => __('Only movements recorded by hand can be voided here.')]);
        }

        $reversal = $this->movements->reverse($entry->id, $reason);

        return JournalEntry::query()->findOrFail($reversal->id);
    }
}
