<?php

use App\Enums\UserRole;
use App\Modules\Accounting\Application\Actions\ClosePeriod;
use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Domain\Journal\JournalEntryDraft;
use App\Modules\Accounting\Domain\Journal\JournalLine;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Church\Infrastructure\Models\ChurchSetting;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Time\Month;
use App\Modules\Treasury\Application\Actions\CloseMonthOfferings;
use App\Modules\Treasury\Application\Actions\CloseWeek;
use App\Modules\Treasury\Application\Closures\WeeklyClosureSchedule;
use App\Modules\Treasury\Infrastructure\Models\Offering;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-12-16 14:00:00');
    signInAs(UserRole::Treasurer);
    ChurchSetting::factory()->create();

    $this->offerings = AccountingAccount::factory()->create(['code' => 'I-002', 'name' => 'OFRENDAS DIARIAS']);
    AccountingAccount::factory()->create(['code' => 'I-007', 'name' => 'APORTES']);
    AccountingAccount::factory()->expense()->create(['code' => 'E-024', 'name' => 'SERVICIOS PÚBLICOS']);
    $this->category = OfferingCategory::factory()->create(['account_id' => $this->offerings->id]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function pdfCop(string $amount): Money
{
    return Money::of($amount, Currency::COP);
}

/**
 * @param  list<JournalLine>  $lines
 */
function pdfBooked(string $date, string $origin, array $lines): void
{
    app(Ledger::class)->post(new JournalEntryDraft($date, "Asiento {$origin}", 'test', $origin, 'test', $lines));
}

function pdfBookedIncome(string $date, string $amount, string $origin): void
{
    pdfBooked($date, $origin, [JournalLine::debit('A-001', pdfCop($amount)), JournalLine::credit('I-007', pdfCop($amount))]);
}

/** October: the opening, an offering, a contribution and an expense; November: the same, smaller. */
function pdfBookOctoberAndNovember(): void
{
    pdfBooked('2026-10-01', 'opening', [JournalLine::debit('A-001', pdfCop('500000.00')), JournalLine::credit('I-001', pdfCop('500000.00'))]);
    Offering::factory()->create(['offering_date' => '2026-10-04', 'offering_category_id' => test()->category->id, 'amount_cop' => '80000.00']);

    for ($week = WeeklyClosureSchedule::next(); $week !== null && $week->key() <= '2026-W44'; $week = WeeklyClosureSchedule::next()) {
        app(CloseWeek::class)($week);
    }
    app(CloseMonthOfferings::class)(Month::fromKey('2026-10'));
    pdfBookedIncome('2026-10-12', '20000.00', 'oct-income');
    pdfBooked('2026-10-20', 'oct-expense', [JournalLine::debit('E-024', pdfCop('30000.50')), JournalLine::credit('A-001', pdfCop('30000.50'))]);
    pdfBookedIncome('2026-11-05', '45000.00', 'nov-income');
}

test('a closed month prints its figures to the cent with no draft mark', function () {
    pdfBookOctoberAndNovember();
    app(ClosePeriod::class)(Month::fromKey('2026-10'));

    $response = $this->get(route('accounting.reports.pdf', ['granularity' => 'month', 'period' => '2026-10']))->assertOk();
    $text = pdfText($response->getContent());

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->getContent())->toStartWith('%PDF')
        ->and($text)->toContain('REPORTE DE CONTABILIDAD - OCTUBRE DE 2026')
        ->and($text)->toContain('OFRENDAS DIARIAS')
        ->and($text)->toContain('80.000,00')
        ->and($text)->toContain('30.000,50')
        ->and($text)->toContain('= SALDO AL CIERRE:')
        // 500.000 opening + 80.000 + 20.000 − 30.000,50
        ->and($text)->toContain('569.999,50')
        ->and($text)->not->toContain('BORRADOR')
        ->and($text)->toContain('Código de verificación');
});

test('a period with an open month says it is a draft', function () {
    pdfBookOctoberAndNovember();
    app(ClosePeriod::class)(Month::fromKey('2026-10'));

    $text = pdfText($this->get(route('accounting.reports.pdf', ['granularity' => 'quarter', 'period' => '2026-Q4']))->getContent());

    expect($text)->toContain('BORRADOR: 2026-11, 2026-12 no están cerrados');
});

test('the same figures give the same verification code, and different figures do not', function () {
    pdfBookOctoberAndNovember();
    $code = fn (): string => preg_match('/verificación ([0-9A-F-]{19})/', pdfText($this->get(route('accounting.reports.pdf', ['granularity' => 'month', 'period' => '2026-10']))->getContent()), $match) === 1 ? $match[1] : '';

    $first = $code();
    expect($code())->toBe($first);

    pdfBookedIncome('2026-10-25', '1.00', 'late-income');

    expect($code())->not->toBe($first)->and($first)->not->toBe('');
});

test('opening the report PDF is a traceable read, and it is refused without church settings', function () {
    pdfBookOctoberAndNovember();

    $this->get(route('accounting.reports.pdf', ['period' => '2026-10', 'granularity' => 'month']))->assertOk();
    expect(ActivityLogEntry::query()->where('action', 'accounting_report.pdf_downloaded')->count())->toBe(1);

    ChurchSetting::query()->delete();
    $this->get(route('accounting.reports.pdf'))->assertStatus(409);
});

test('who cannot read the books is refused', function () {
    signInAs(UserRole::Member);

    $this->get(route('accounting.reports.pdf'))->assertForbidden();
});
