<?php

use App\Enums\UserRole;
use App\Modules\Members\Application\MemberDirectory;
use App\Modules\Members\Infrastructure\Models\Member;
use App\Modules\Shared\Time\ReportPeriod;
use App\Modules\Treasury\Application\Tithes\TitheAnalysis;
use App\Modules\Treasury\Application\Tithes\TitheReport;
use App\Modules\Treasury\Infrastructure\Models\Tithe;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Carbon::setTestNow('2026-11-16 14:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function insightMember(string $name): Member
{
    return Member::factory()->create(['full_name' => $name]);
}

/**
 * @param  array<string, string>  $amounts  amount_ves, amount_usd, amount_cop
 */
function insightTithe(Member $member, string $date, array $amounts): Tithe
{
    return Tithe::factory()->create(['member_id' => $member->id, 'tithe_date' => $date, 'amount_ves' => '0', 'amount_usd' => '0', 'amount_cop' => '0', ...$amounts]);
}

/**
 * August 2026 has five Sundays (2, 9, 16, 23, 30) and touches six ISO weeks.
 *
 * @return array<string, array<string, mixed>> the analysis rows of August by member name
 */
function augustAnalysis(): array
{
    $rows = TitheAnalysis::of(ReportPeriod::fromKey('month', '2026-08'), app(MemberDirectory::class))['rows'];

    return array_column($rows, null, 'name');
}

test('who tithed all five Sundays of a month gives five of five, not five of the six ISO weeks it touches', function () {
    $ana = insightMember('Ana');

    foreach (['2026-08-02', '2026-08-09', '2026-08-16', '2026-08-23', '2026-08-30'] as $sunday) {
        insightTithe($ana, $sunday, ['amount_cop' => '10000.00']);
    }

    $row = augustAnalysis()['Ana'];

    expect(TitheAnalysis::of(ReportPeriod::fromKey('month', '2026-08'), app(MemberDirectory::class))['sundays'])->toBe(5)
        ->and($row['weeks'])->toBe(5)
        ->and($row['totals']['cop'])->toBe('50000.00')
        ->and($row['average_given']['cop'])->toBe('10000.00')
        // Over six weeks it would be 8333.33: an accusation that she missed one.
        ->and($row['average_period']['cop'])->toBe('10000.00');
});

test('the two averages tell different things: what they give when they give, and overall', function () {
    $beto = insightMember('Beto');
    insightTithe($beto, '2026-08-09', ['amount_cop' => '10000.00']);
    insightTithe($beto, '2026-08-23', ['amount_cop' => '10000.00']);

    $row = augustAnalysis()['Beto'];

    expect($row['weeks'])->toBe(2)
        ->and($row['average_given']['cop'])->toBe('10000.00')
        ->and($row['average_period']['cop'])->toBe('4000.00');
});

test('amounts are rounded to cents by the database and each currency apart', function () {
    $hugo = insightMember('Hugo');
    insightTithe($hugo, '2026-08-02', ['amount_usd' => '1.00', 'amount_ves' => '100.00']);
    insightTithe($hugo, '2026-08-09', ['amount_usd' => '1.00']);
    insightTithe($hugo, '2026-08-16', ['amount_usd' => '1.01']);

    $row = augustAnalysis()['Hugo'];

    expect($row['totals'])->toBe(['ves' => '100.00', 'usd' => '3.01', 'cop' => '0.00'])
        // 3.01 / 3 weeks = 1.0033…, 3.01 / 5 Sundays = 0.602.
        ->and($row['average_given']['usd'])->toBe('1.00')
        ->and($row['average_period']['usd'])->toBe('0.60')
        // The weeks are those in which they tithed in any currency (3), not per currency: 100 / 3.
        ->and($row['average_given']['ves'])->toBe('33.33')
        // A currency they never tithed in averages zero; "no average" is only for who did not tithe at all.
        ->and($row['average_given']['cop'])->toBe('0.00');
});

test('two tithes in the same ISO week count as one week', function () {
    $iris = insightMember('Iris');
    insightTithe($iris, '2026-08-03', ['amount_cop' => '100.00']);
    insightTithe($iris, '2026-08-09', ['amount_cop' => '100.00']);

    $row = augustAnalysis()['Iris'];

    expect($row['weeks'])->toBe(1)
        ->and($row['average_given']['cop'])->toBe('200.00')
        ->and($row['average_period']['cop'])->toBe('40.00');
});

test('a member who stopped is told apart from one who never tithed', function () {
    $elena = insightMember('Elena');
    insightTithe($elena, '2026-06-07', ['amount_cop' => '5000.00']);
    insightMember('Fausto');
    $rows = augustAnalysis();

    expect($rows['Elena']['weeks'])->toBe(0)
        ->and($rows['Elena']['totals']['cop'])->toBe('0.00')
        ->and($rows['Elena']['last_date'])->toBe('2026-06-07')
        // From 7 June to 16 November there are 162 days: 23 whole weeks.
        ->and($rows['Elena']['weeks_ago'])->toBe(23)
        ->and($rows['Fausto']['last_date'])->toBeNull()
        ->and($rows['Fausto']['weeks_ago'])->toBeNull()
        ->and($rows['Fausto']['average_given']['cop'])->toBeNull()
        ->and($rows['Fausto']['average_period']['cop'])->toBe('0.00');
});

test('the last tithe is the last ever, not only the last of the period', function () {
    $beto = insightMember('Beto');
    insightTithe($beto, '2026-08-09', ['amount_cop' => '10000.00']);
    insightTithe($beto, '2026-09-06', ['amount_cop' => '99999.00']);

    $row = augustAnalysis()['Beto'];

    expect($row['totals']['cop'])->toBe('10000.00')
        ->and($row['last_date'])->toBe('2026-09-06');
});

test('trashed tithes and trashed members stay out of the analysis', function () {
    $ana = insightMember('Ana');
    insightTithe($ana, '2026-08-02', ['amount_cop' => '1000.00']);
    insightTithe($ana, '2026-08-09', ['amount_cop' => '9000.00'])->delete();
    $gone = insightMember('Gabi');
    insightTithe($gone, '2026-08-02', ['amount_cop' => '500.00']);
    $gone->delete();

    $rows = augustAnalysis();

    expect($rows['Ana']['totals']['cop'])->toBe('1000.00')
        ->and($rows['Ana']['weeks'])->toBe(1)
        ->and($rows)->not->toHaveKey('Gabi');
});

test('the analysis lists the whole registry by name', function () {
    foreach (['Zulay', 'Ana', 'Mario'] as $name) {
        insightMember($name);
    }

    $names = array_column(TitheAnalysis::of(ReportPeriod::fromKey('month', '2026-08'), app(MemberDirectory::class))['rows'], 'name');

    expect($names)->toBe(['Ana', 'Mario', 'Zulay']);
});

test('the report adds up the months of the period per currency and lists who tithed, by name', function () {
    $beto = insightMember('Beto');
    $ana = insightMember('Ana');
    insightTithe($beto, '2026-07-05', ['amount_cop' => '1000.50']);
    insightTithe($beto, '2026-09-27', ['amount_cop' => '2000.25', 'amount_usd' => '5.00']);
    insightTithe($ana, '2026-08-02', ['amount_ves' => '300.00']);
    insightTithe($ana, '2026-10-04', ['amount_cop' => '77777.00']);
    insightTithe($ana, '2026-08-09', ['amount_cop' => '4444.00'])->delete();

    $report = TitheReport::of(ReportPeriod::fromKey('quarter', '2026-Q3'), app(MemberDirectory::class));

    expect($report['totals'])->toBe(['ves' => '300.00', 'usd' => '5.00', 'cop' => '3000.75'])
        ->and($report['members_who_tithed'])->toBe(2)
        ->and($report['months'])->toBe(['2026-07', '2026-08', '2026-09'])
        ->and($report['short_label'])->toBe('3.º trimestre de 2026')
        ->and(array_column($report['members'], 'name'))->toBe(['Ana', 'Beto'])
        ->and($report['members'][1])->toMatchArray(['cop' => '3000.75', 'usd' => '5.00', 'weeks' => 2]);
});

test('the report still names a member who was trashed after tithing', function () {
    $gone = insightMember('Gabi');
    insightTithe($gone, '2026-08-02', ['amount_cop' => '500.00']);
    $gone->delete();

    $report = TitheReport::of(ReportPeriod::fromKey('month', '2026-08'), app(MemberDirectory::class));

    expect($report['members_who_tithed'])->toBe(1)
        ->and($report['members'][0]['name'])->toBe('Gabi');
});

test('an empty period reports zeros', function () {
    $report = TitheReport::of(ReportPeriod::fromKey('month', '2026-08'), app(MemberDirectory::class));

    expect($report['totals'])->toBe(['ves' => '0.00', 'usd' => '0.00', 'cop' => '0.00'])
        ->and($report['members'])->toBe([]);
});

test('the pastor reads the report and the analysis from the URL, falling back to the current month', function () {
    signInAs(UserRole::Pastor);
    insightTithe(insightMember('Ana'), '2026-08-02', ['amount_cop' => '1000.00']);

    $this->get(route('treasury.tithes.reports', ['granularity' => 'quarter', 'period' => '2026-Q3']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('treasury/tithes/reports')
            ->where('report.period', '2026-Q3')
            ->where('report.totals.cop', '1000.00')
            ->where('options.quarter.0.key', '2026-Q4')
            ->where('options.quarter.1.key', '2026-Q3'));

    $this->get(route('treasury.tithes.analysis', ['granularity' => 'month', 'period' => '2026-08']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('treasury/tithes/analysis')
            ->where('analysis.sundays', 5)
            ->where('analysis.rows.0.name', 'Ana')
            ->where('analysis.rows.0.weeks', 1)
            ->where('today', '2026-11-16'));

    $this->get(route('treasury.tithes.reports', ['granularity' => 'month', 'period' => 'nonsense']))
        ->assertInertia(fn (Assert $page) => $page->where('report.period', '2026-11'));
});

test('a shared link to a period before the first tithe still shows its months', function () {
    signInAs(UserRole::Pastor);

    $this->get(route('treasury.tithes.reports', ['granularity' => 'range', 'period' => '2026-01..2026-02']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.label', 'Enero – febrero de 2026')
            ->where('options.month', fn ($months) => collect($months)->pluck('key')->intersect(['2026-01', '2026-02'])->count() === 2));
});

test('only the pastor reads the report and the analysis', function (UserRole $role) {
    signInAs($role);

    $this->get(route('treasury.tithes.reports'))->assertForbidden();
    $this->get(route('treasury.tithes.analysis'))->assertForbidden();
})->with([UserRole::Treasurer, UserRole::Comptroller, UserRole::Secretary]);
