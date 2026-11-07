<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Modules\Accounting\Application\Actions\CorrectMovement;
use App\Modules\Accounting\Application\Actions\RecordCurrencyExchange;
use App\Modules\Accounting\Application\Actions\RecordManualMovement;
use App\Modules\Accounting\Application\Actions\VoidMovement;
use App\Modules\Accounting\Application\Cash\AvailableCash;
use App\Modules\Accounting\Application\Periods\PeriodSchedule;
use App\Modules\Accounting\Application\Queries\MonthSummary;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemFunction;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Accounting\Infrastructure\Models\AccountingPeriod;
use App\Modules\Accounting\Infrastructure\Models\JournalEntry;
use App\Modules\Accounting\Infrastructure\Models\JournalEntryLine;
use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Tables\TableQuery;
use App\Modules\Shared\Time\ChurchCalendar;
use App\Modules\Shared\Time\Month;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The journal (Libro Diario): a month's cash statement, its income and expense by code, its
 * entries, and the movements recorded by hand (step 3 of the month circuit). accounting.view to
 * read; accounting.manage to record, void and correct.
 */
class JournalController extends Controller
{
    public function index(Request $request, PeriodSchedule $schedule, AvailableCash $cash): Response
    {
        $window = $schedule->openWindow();
        $current = Month::containing(ChurchCalendar::today())->key();
        $month = $this->requestedMonth($request) ?? Month::fromKey($window['to']);
        $isClosed = AccountingPeriod::isClosed($month->key());
        $isOpen = ! $isClosed && ($window['from'] === null || $month->key() >= $window['from']) && $month->key() <= $window['to'];
        $previousClosed = AccountingPeriod::isClosed($month->previous()->key());
        $hasEarlier = JournalEntry::query()->where('month', '<', $month->key())->exists();

        $table = TableQuery::fromRequest($request, ['date' => 'journal_entries.entry_date'], 'date', defaultDirection: 'desc', withStatus: false);
        $entries = $table->paginate(
            JournalEntry::query()->where('journal_entries.month', $month->key()),
            [fn (Builder $query, string $pattern) => $query->whereRaw('lower(immutable_unaccent(journal_entries.description)) like ?', [$pattern])],
            fn (Builder $query) => $query->with(['lines.account', 'reversal']),
        );

        return Inertia::render('accounting/journal/index', [
            'month' => $month->key(),
            'status' => $isClosed ? 'closed' : ($isOpen ? 'open' : 'unavailable'),
            'is_opening_provisional' => ! $isClosed && ! $previousClosed && $hasEarlier,
            'summary' => MonthSummary::of($month),
            'available' => $isOpen ? [
                'VES' => $cash->on(Currency::VES, ChurchCalendar::today()->format('Y-m-d'))->amount(),
                'USD' => $cash->on(Currency::USD, ChurchCalendar::today()->format('Y-m-d'))->amount(),
                'COP' => $cash->on(Currency::COP, ChurchCalendar::today()->format('Y-m-d'))->amount(),
            ] : null,
            'entries' => $entries->through(fn (JournalEntry $entry) => self::row($entry, $isOpen)),
            'filters' => [...$table->filters(), 'month' => $month->key()],
            'months' => $this->months($window, $current),
            'window' => [
                'min' => $window['from'] === null ? null : Month::fromKey($window['from'])->firstDay->format('Y-m-d'),
                'max' => ChurchCalendar::today()->format('Y-m-d'),
            ],
            'accounts' => [
                'income' => self::accounts(AccountType::Income),
                'expense' => self::accounts(AccountType::Expense),
            ],
            'can_manage' => $request->user()?->can(Permission::AccountingManage->value) ?? false,
        ]);
    }

    public function store(Request $request, RecordManualMovement $record): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['income', 'expense'])],
            'account_code' => ['required', 'string', 'max:10'],
            'currency' => ['required', Rule::in(['VES', 'USD', 'COP'])],
            'amount' => ['required', 'string', 'max:20'],
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:255'],
            'token' => ['required', 'uuid'],
        ]);

        $record($data);
        Inertia::flash('toast', ['type' => 'success', 'message' => $data['kind'] === 'income' ? __('Income recorded.') : __('Expense recorded.')]);

        return back();
    }

    public function exchange(Request $request, RecordCurrencyExchange $exchange): RedirectResponse
    {
        $data = $request->validate([
            'from_currency' => ['required', Rule::in(['VES', 'USD', 'COP'])],
            'from_amount' => ['required', 'string', 'max:20'],
            'to_currency' => ['required', Rule::in(['VES', 'USD', 'COP'])],
            'to_amount' => ['required', 'string', 'max:20'],
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:255'],
            'token' => ['required', 'uuid'],
        ]);

        $exchange($data);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Currency exchange recorded.')]);

        return back();
    }

    public function cancel(Request $request, JournalEntry $entry, VoidMovement $void): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:250']]);

        $void($entry, $data['reason']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Movement voided with a reversal.')]);

        return back();
    }

    public function correct(Request $request, JournalEntry $entry, CorrectMovement $correct): RedirectResponse
    {
        $data = $request->validate([
            'account_code' => ['required', 'string', 'max:10'],
            'currency' => ['required', Rule::in(['VES', 'USD', 'COP'])],
            'amount' => ['required', 'string', 'max:20'],
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:255'],
            'token' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:250'],
        ]);

        $correct($entry, $data);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Movement corrected: the original was reversed and the correction recorded.')]);

        return back();
    }

    private function requestedMonth(Request $request): ?Month
    {
        $month = $request->query('month');

        return is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1 ? Month::fromKey($month) : null;
    }

    /**
     * Months to choose from: the open ones and every month with entries or closed, newest first.
     *
     * @param  array{from: string|null, to: string}  $window
     * @return list<string>
     */
    private function months(array $window, string $current): array
    {
        $months = JournalEntry::query()->distinct()->pluck('month')->map(fn ($month) => (string) $month)->all();
        $months[] = $window['to'];
        $months[] = $current;

        if ($window['from'] !== null) {
            $months[] = $window['from'];
        }

        $months = array_values(array_unique($months));
        rsort($months);

        return array_slice($months, 0, 36);
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    private static function accounts(AccountType $type): array
    {
        return array_values(AccountingAccount::query()
            ->where('type', $type->value)
            ->whereNull('system_function')
            ->where('is_active', true)
            ->orderedByCode()
            ->get(['code', 'name'])
            ->map(fn (AccountingAccount $account) => ['code' => $account->code, 'name' => $account->name])
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(JournalEntry $entry, bool $isOpenMonth): array
    {
        $cash = ['VES' => Money::zero(Currency::VES), 'USD' => Money::zero(Currency::USD), 'COP' => Money::zero(Currency::COP)];
        $codes = [];

        foreach ($entry->lines as $line) {
            /** @var JournalEntryLine $line */
            if ($line->account->system_function === SystemFunction::Cash) {
                $cash[$line->currency->value] = $cash[$line->currency->value]->plus($line->debitMoney())->minus($line->creditMoney());
            } else {
                $codes[$line->account->code] = $line->account->name;
            }
        }

        $isManual = in_array($entry->origin_type, VoidMovement::MANUAL_ORIGINS, true);
        $isActive = $entry->reversal_of === null && $entry->reversal === null;

        return [
            'id' => $entry->id,
            'entry_date' => $entry->entry_date->toDateString(),
            'description' => $entry->description,
            'origin' => $entry->reversal_of !== null ? 'reversal' : $entry->origin_type,
            'origin_purpose' => $entry->origin_purpose,
            'codes' => array_map(fn (string $code, string $name) => ['code' => $code, 'name' => $name], array_keys($codes), $codes),
            'cash' => array_map(fn (Money $money) => $money->amount(), $cash),
            'reversal_reason' => $entry->reversal_reason,
            'is_reversed' => $entry->reversal !== null,
            'can_void' => $isManual && $isActive,
            'can_correct' => $isOpenMonth && $entry->origin_type === 'manual_movement' && $isActive,
        ];
    }
}
