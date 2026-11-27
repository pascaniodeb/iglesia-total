<?php

namespace App\Modules\Accounting\Infrastructure;

use App\Modules\Accounting\Application\Actions\VerifyJournal;
use App\Modules\Integrity\Domain\IntegrityCheck;

/**
 * The journal balances: debits equal credits in every currency, every entry is balanced and every
 * reversal mirrors its original.
 */
final class JournalBalanceCheck implements IntegrityCheck
{
    public function __construct(private readonly VerifyJournal $verify) {}

    public function id(): string
    {
        return 'journal.balance';
    }

    public function title(): string
    {
        return __('The journal balances in every currency, entry by entry');
    }

    public function findings(): array
    {
        $result = ($this->verify)();
        $findings = [];

        foreach ($result['currencies'] as $currency => $totals) {
            if (! $totals['balanced']) {
                $findings[] = __('Currency :currency does not balance: debit :debit, credit :credit.', ['currency' => $currency, 'debit' => $totals['debit'], 'credit' => $totals['credit']]);
            }
        }

        foreach ($result['unbalanced_entries'] as $id) {
            $findings[] = __('Entry :id is not balanced.', ['id' => $id]);
        }

        foreach ($result['mismatched_reversals'] as $id) {
            $findings[] = __('Reversal :id does not mirror its original.', ['id' => $id]);
        }

        return $findings;
    }
}
