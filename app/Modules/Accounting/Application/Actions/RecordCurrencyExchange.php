<?php

namespace App\Modules\Accounting\Application\Actions;

use App\Modules\Accounting\Application\Cash\AvailableCash;
use App\Modules\Accounting\Application\Cash\MovementGuard;
use App\Modules\Accounting\Domain\Enums\MovementKind;
use App\Modules\Accounting\Domain\Journal\JournalEntryDraft;
use App\Modules\Accounting\Domain\Journal\JournalLine;
use App\Modules\Accounting\Infrastructure\Models\JournalEntry;
use App\Modules\Shared\Money\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a currency exchange (e.g. 100 USD sold for 360.000 COP) with the amounts actually
 * exchanged, never computed from a rate. It goes through PUENTE CAMBIARIO so each currency of
 * the entry balances on its own:
 *
 *   debit CAJA (received currency), credit PUENTE (received currency)
 *   debit PUENTE (given currency),  credit CAJA (given currency)
 *
 * The currency given leaves the cash, so D4 applies to it, under its lock.
 */
final readonly class RecordCurrencyExchange
{
    public function __construct(private PostJournalEntry $post, private MovementGuard $guard) {}

    /**
     * @param  array{from_currency: string, from_amount: string, to_currency: string, to_amount: string, entry_date: string, description: string, token: string}  $data
     *
     * @throws ValidationException
     */
    public function __invoke(array $data): JournalEntry
    {
        $from = Currency::from($data['from_currency']);
        $to = Currency::from($data['to_currency']);

        if ($from === $to) {
            throw ValidationException::withMessages(['to_currency' => __('An exchange goes from one currency to another.')]);
        }

        $given = RecordManualMovement::amount($data['from_amount'], $from, 'from_amount');
        $received = RecordManualMovement::amount($data['to_amount'], $to, 'to_amount');

        $this->guard->assertOpenDate($data['entry_date']);

        return DB::transaction(function () use ($from, $given, $received, $data): JournalEntry {
            AvailableCash::lock($from);
            $available = $this->guard->availableBefore($from, $data['entry_date']);

            $entry = ($this->post)(new JournalEntryDraft(
                entryDate: $data['entry_date'],
                description: trim($data['description']),
                originType: MovementKind::Exchange->originType(),
                originId: $data['token'],
                originPurpose: MovementKind::Exchange->value,
                lines: [
                    JournalLine::debit('A-001', $received),
                    JournalLine::credit('A-002', $received),
                    JournalLine::debit('A-002', $given),
                    JournalLine::credit('A-001', $given),
                ],
            ));

            $this->guard->assertNoOverdraft($from, $data['entry_date'], $available, $given, 'from_amount');

            return $entry;
        });
    }
}
