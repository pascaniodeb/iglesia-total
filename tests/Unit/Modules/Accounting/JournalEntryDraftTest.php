<?php

use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Journal\JournalEntryDraft;
use App\Modules\Accounting\Domain\Journal\JournalLine;
use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;

function cop(string $amount): Money
{
    return Money::of($amount, Currency::COP);
}

function usd(string $amount): Money
{
    return Money::of($amount, Currency::USD);
}

/**
 * @param  list<JournalLine>  $lines
 */
function draft(array $lines, string $date = '2026-11-02'): JournalEntryDraft
{
    return new JournalEntryDraft($date, 'Ofrendas de la semana', 'test', 'abc', 'income', $lines);
}

test('a balanced entry in several currencies is accepted and totals per currency', function () {
    $entry = draft([
        JournalLine::debit('A-001', cop('141000.00')),
        JournalLine::credit('I-002', cop('141000.00')),
        JournalLine::debit('A-001', usd('10.00')),
        JournalLine::credit('I-002', usd('10.00')),
    ]);

    expect($entry->month)->toBe('2026-11')
        ->and(array_map(fn (Money $money) => $money->amount(), $entry->totalsByCurrency()))
        ->toBe(['COP' => '141000.00', 'USD' => '10.00']);
});

test('refuses an entry that does not balance in one currency, even if the numbers add up overall', function () {
    expect(fn () => draft([
        JournalLine::debit('A-001', cop('10.00')),
        JournalLine::credit('I-002', usd('10.00')),
    ]))->toThrow(InvalidArgumentException::class, 'does not balance in COP');
});

test('refuses one-line entries, lines on both sides or none, and bad dates or origins', function (Closure $build) {
    expect($build)->toThrow(InvalidArgumentException::class);
})->with([
    'one line' => [fn () => draft([JournalLine::debit('A-001', cop('1.00'))])],
    'both sides' => [fn () => new JournalLine('A-001', cop('1.00'), cop('1.00'))],
    'no side' => [fn () => new JournalLine('A-001', cop('0'), cop('0'))],
    'negative' => [fn () => JournalLine::debit('A-001', cop('-1.00'))],
    'mixed currencies in a line' => [fn () => new JournalLine('A-001', cop('1.00'), usd('0'))],
    'bad code' => [fn () => JournalLine::debit('CAJA', cop('1.00'))],
    'impossible date' => [fn () => draft([JournalLine::debit('A-001', cop('1.00')), JournalLine::credit('I-002', cop('1.00'))], '2026-02-30')],
    'bad origin' => [fn () => new JournalEntryDraft('2026-11-02', 'X', 'Not An Id', 'abc', 'income', [JournalLine::debit('A-001', cop('1.00')), JournalLine::credit('I-002', cop('1.00'))])],
]);

test('an inverted line swaps debit and credit', function () {
    $line = JournalLine::debit('A-001', cop('5.50'))->inverted();

    expect($line->debit->isZero())->toBeTrue()
        ->and($line->credit->amount())->toBe('5.50');
});

test('balances follow the normal side of each account type', function () {
    expect(AccountType::Asset->balance(cop('100.00'), cop('30.00'))->amount())->toBe('70.00')
        ->and(AccountType::Income->balance(cop('30.00'), cop('100.00'))->amount())->toBe('70.00')
        ->and(AccountType::Expense->balance(cop('10.00'), cop('0'))->amount())->toBe('10.00')
        ->and(AccountType::Equity->balance(cop('0'), cop('591700.00'))->amount())->toBe('591700.00');
});
