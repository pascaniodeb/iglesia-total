<?php

namespace App\Modules\Accounting\Application\Actions;

use App\Modules\Accounting\Domain\Journal\JournalEntryDraft;
use App\Modules\Accounting\Domain\Journal\JournalLine;
use App\Modules\Accounting\Domain\Journal\LedgerConflict;
use App\Modules\Accounting\Infrastructure\JournalConstraints;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Accounting\Infrastructure\Models\JournalEntry;
use App\Modules\Accounting\Infrastructure\Models\JournalEntryLine;
use App\Modules\Integrity\Application\ActivityLog;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Posts a journal entry, atomically and idempotently by origin.
 *
 * The same origin with the same content returns the entry already posted (a retry is safe);
 * with different content it is a LedgerConflict. Accounts are resolved by code and must be
 * active. The balance rules are checked before returning, and the posting is recorded in the
 * activity log in the same transaction.
 *
 * It does not authorize: the use case that calls it checks its own permission.
 */
final readonly class PostJournalEntry
{
    public function __construct(private ActivityLog $activityLog, private AuthFactory $auth) {}

    /**
     * @throws LedgerConflict
     */
    public function __invoke(JournalEntryDraft $draft): JournalEntry
    {
        return DB::transaction(function () use ($draft): JournalEntry {
            $existing = JournalEntry::query()
                ->where('origin_type', $draft->originType)
                ->where('origin_id', $draft->originId)
                ->where('origin_purpose', $draft->originPurpose)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (self::fingerprintOfStored($existing) !== self::fingerprintOfDraft($draft)) {
                    throw new LedgerConflict('The origin :origin was already posted with different content.', [
                        'origin' => "{$draft->originType}/{$draft->originId}/{$draft->originPurpose}",
                    ]);
                }

                return $existing->load('lines.account');
            }

            $entry = $this->insert($draft, $this->accounts($draft->lines));

            $this->activityLog->record(
                'journal_entry.posted',
                __('Posted the entry ":description" of :date.', ['description' => $entry->description, 'date' => $draft->entryDate]),
                $entry,
                [
                    'origin' => "{$draft->originType}/{$draft->originId}/{$draft->originPurpose}",
                    'totals' => array_map(fn ($money) => $money->amount(), $draft->totalsByCurrency()),
                ],
            );

            return $entry->load('lines.account');
        });
    }

    /**
     * Inserts the entry and its lines, then has the database check its balance rules now.
     *
     * @param  Collection<string, AccountingAccount>  $accounts  by code
     * @param  array{reversal_of: string, reversal_reason: string}|null  $reversal
     */
    public function insert(JournalEntryDraft $draft, Collection $accounts, ?array $reversal = null): JournalEntry
    {
        $actorId = $this->auth->guard()->id();

        $entry = JournalEntry::query()->create([
            'entry_date' => $draft->entryDate,
            'month' => $draft->month,
            'description' => $draft->description,
            'origin_type' => $draft->originType,
            'origin_id' => $draft->originId,
            'origin_purpose' => $draft->originPurpose,
            'reversal_of' => $reversal['reversal_of'] ?? null,
            'reversal_reason' => $reversal['reversal_reason'] ?? null,
            'lines_count' => count($draft->lines),
            'created_by' => $actorId === null ? null : (string) $actorId,
        ]);

        foreach ($draft->lines as $index => $line) {
            JournalEntryLine::query()->create([
                'journal_entry_id' => $entry->id,
                'line_number' => $index + 1,
                'account_id' => $accounts->get($line->accountCode)?->id,
                'currency' => $line->currency,
                'debit' => $line->debit->amount(),
                'credit' => $line->credit->amount(),
                'description' => $line->description,
            ]);
        }

        JournalConstraints::checkNow();

        return $entry;
    }

    /**
     * The accounts of the lines, by code. Every code must exist and be active.
     *
     * @param  list<JournalLine>  $lines
     * @return Collection<string, AccountingAccount>
     *
     * @throws LedgerConflict
     */
    public function accounts(array $lines): Collection
    {
        $codes = array_values(array_unique(array_map(fn (JournalLine $line) => $line->accountCode, $lines)));
        $accounts = AccountingAccount::query()->whereIn('code', $codes)->get()->keyBy('code');

        foreach ($codes as $code) {
            $account = $accounts->get($code) ?? throw new LedgerConflict('There is no account :code.', ['code' => $code]);

            if (! $account->is_active) {
                throw new LedgerConflict('The account :code is inactive.', ['code' => $code]);
            }
        }

        return $accounts;
    }

    private static function fingerprintOfDraft(JournalEntryDraft $draft): string
    {
        return (string) json_encode([
            $draft->entryDate,
            $draft->description,
            array_map(fn (JournalLine $line) => [
                $line->accountCode, $line->currency->value, $line->debit->amount(), $line->credit->amount(), $line->description,
            ], $draft->lines),
        ]);
    }

    private static function fingerprintOfStored(JournalEntry $entry): string
    {
        $entry->loadMissing('lines.account');

        return (string) json_encode([
            $entry->entry_date->toDateString(),
            $entry->description,
            $entry->lines->map(fn (JournalEntryLine $line) => [
                $line->account->code, $line->currency->value, $line->debit, $line->credit, $line->description,
            ])->all(),
        ]);
    }
}
