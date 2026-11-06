<?php

namespace App\Modules\Accounting\Infrastructure\Models;

use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of a journal entry. Amounts are read as decimal text and handled as Money, never as
 * floats.
 *
 * @property string $id UUIDv7
 * @property string $journal_entry_id
 * @property int $line_number
 * @property string $account_id
 * @property Currency $currency
 * @property string $debit
 * @property string $credit
 * @property string|null $description
 * @property-read AccountingAccount $account
 * @property-read JournalEntry $entry
 */
#[Fillable(['journal_entry_id', 'line_number', 'account_id', 'currency', 'debit', 'credit', 'description'])]
class JournalEntryLine extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<AccountingAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'account_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function debitMoney(): Money
    {
        return Money::of($this->debit, $this->currency);
    }

    public function creditMoney(): Money
    {
        return Money::of($this->credit, $this->currency);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'currency' => Currency::class,
        ];
    }
}
