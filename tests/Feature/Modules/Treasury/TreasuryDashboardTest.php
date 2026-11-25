<?php

use App\Modules\Shared\Time\ChurchCalendar;
use App\Modules\Treasury\Application\Queries\TreasuryDashboard;
use App\Modules\Treasury\Infrastructure\Models\Offering;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-11-25 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

test('the month is added up in exact decimals against the month before, per currency', function () {
    $category = OfferingCategory::factory()->create(['name' => 'OFRENDA DIARIA']);
    Offering::factory()->create(['offering_category_id' => $category->id, 'offering_date' => '2026-11-08', 'amount_cop' => '100000.10', 'amount_usd' => '5.05']);
    Offering::factory()->create(['offering_category_id' => $category->id, 'offering_date' => '2026-11-15', 'amount_cop' => '50000.20']);
    Offering::factory()->create(['offering_category_id' => $category->id, 'offering_date' => '2026-10-04', 'amount_cop' => '30000.00']);

    $offerings = TreasuryDashboard::offerings(ChurchCalendar::today());

    expect($offerings['month'])->toBe('2026-11')
        ->and($offerings['previous_month'])->toBe('2026-10')
        ->and($offerings['current'])->toBe(['count' => 2, 'ves' => '0.00', 'usd' => '5.05', 'cop' => '150000.30'])
        ->and($offerings['previous']['cop'])->toBe('30000.00');
});

test('an offering in the trash does not count anywhere', function () {
    $category = OfferingCategory::factory()->create();
    Offering::factory()->create(['offering_category_id' => $category->id, 'offering_date' => '2026-11-08', 'amount_cop' => '999.00', 'deleted_at' => now()]);

    $offerings = TreasuryDashboard::offerings(ChurchCalendar::today());

    expect($offerings['current']['count'])->toBe(0)
        ->and($offerings['categories'])->toBe([])
        ->and(collect($offerings['weeks'])->sum('count'))->toBe(0);
});

test('the trend has the last twelve weeks, oldest first, with each offering in its own week', function () {
    $category = OfferingCategory::factory()->create();
    Offering::factory()->create(['offering_category_id' => $category->id, 'offering_date' => '2026-11-22', 'amount_cop' => '10.00']);

    $weeks = TreasuryDashboard::offerings(ChurchCalendar::today())['weeks'];
    $week = collect($weeks)->firstWhere('key', '2026-W47');

    expect($weeks)->toHaveCount(12)
        ->and($weeks[11]['key'])->toBe('2026-W48')
        ->and($weeks[0]['key'])->toBe('2026-W37')
        ->and($week['cop'])->toBe('10.00')
        ->and($week['count'])->toBe(1)
        ->and($week['closed'])->toBeFalse();
});

test('the categories come biggest first', function () {
    $small = OfferingCategory::factory()->create(['name' => 'DONACIONES']);
    $big = OfferingCategory::factory()->create(['name' => 'OFRENDA DIARIA']);
    Offering::factory()->create(['offering_category_id' => $small->id, 'offering_date' => '2026-11-08', 'amount_cop' => '1.00']);
    Offering::factory()->create(['offering_category_id' => $big->id, 'offering_date' => '2026-11-08', 'amount_cop' => '2.00']);

    expect(array_column(TreasuryDashboard::offerings(ChurchCalendar::today())['categories'], 'name'))->toBe(['OFRENDA DIARIA', 'DONACIONES']);
});

test('the weeks that have ended and are not closed are pending, and the current one is not', function () {
    $category = OfferingCategory::factory()->create();
    Offering::factory()->create(['offering_category_id' => $category->id, 'offering_date' => '2026-11-08', 'amount_cop' => '1.00']);

    $pending = TreasuryDashboard::pending(ChurchCalendar::today());

    expect(array_column($pending['weeks'], 'key'))->toBe(['2026-W45', '2026-W46', '2026-W47'])
        ->and($pending['weeks_pending'])->toBe(3)
        ->and($pending['week_can_close'])->toBeTrue();
});

test('with nothing recorded there is nothing pending and no rates', function () {
    $pending = TreasuryDashboard::pending(ChurchCalendar::today());

    expect($pending['weeks'])->toBe([])
        ->and($pending['weeks_pending'])->toBe(0)
        ->and($pending['month'])->toBeNull()
        ->and(TreasuryDashboard::rates(ChurchCalendar::today()))->toBeNull();
});
