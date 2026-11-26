<?php

use App\Enums\UserRole;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Church\Infrastructure\Models\ChurchSetting;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Shared\Time\Month;
use App\Modules\Treasury\Application\Actions\CloseMonthOfferings;
use App\Modules\Treasury\Application\Actions\CloseWeek;
use App\Modules\Treasury\Application\Closures\WeeklyClosureSchedule;
use App\Modules\Treasury\Infrastructure\Models\Offering;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use Illuminate\Support\Carbon;

beforeEach(function () {
    // Wednesday 16 December 2026: October and November have ended.
    Carbon::setTestNow('2026-12-16 14:00:00');
    signInAs(UserRole::Treasurer);
    ChurchSetting::factory()->create();

    $account = AccountingAccount::factory()->create(['code' => 'I-002', 'name' => 'OFRENDAS DIARIAS']);
    $category = OfferingCategory::factory()->create(['name' => 'OFRENDA DIARIA', 'account_id' => $account->id]);
    Offering::factory()->create(['offering_date' => '2026-10-04', 'offering_category_id' => $category->id, 'amount_ves' => '0.00', 'amount_usd' => '5.00', 'amount_cop' => '80000.50']);
    Offering::factory()->create(['offering_date' => '2026-10-18', 'offering_category_id' => $category->id, 'amount_ves' => '0.00', 'amount_usd' => '0.00', 'amount_cop' => '20000.00']);
});

afterEach(function () {
    Carbon::setTestNow();
});

function closeOctoberOfferings(): void
{
    for ($week = WeeklyClosureSchedule::next(); $week !== null && $week->key() <= '2026-W44'; $week = WeeklyClosureSchedule::next()) {
        app(CloseWeek::class)($week);
    }

    app(CloseMonthOfferings::class)(Month::fromKey('2026-10'));
}

test('the closed month prints its weeks and its categories with the account code and the totals', function () {
    closeOctoberOfferings();

    $response = $this->get(route('treasury.monthly-offering-closures.pdf', '2026-10'))->assertOk();
    $text = pdfText($response->getContent());

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($text)->toContain('CIERRE MENSUAL DE OFRENDAS - OCTUBRE DE 2026')
        ->and($text)->toContain('SEMANAS DEL MES')
        ->and($text)->toContain('I-002')
        ->and($text)->toContain('OFRENDA DIARIA')
        ->and($text)->toContain('TOTAL DEL MES:')
        ->and($text)->toContain('100.000,50')
        ->and($text)->toContain('5,00')
        ->and($text)->toContain('Mes contable aún sin sellar');
});

test('a month that is not closed has no document, and a malformed month does not exist', function () {
    $this->get(route('treasury.monthly-offering-closures.pdf', '2026-10'))->assertStatus(409);
    $this->get('/treasury/monthly-offering-closures/2026-13/pdf')->assertNotFound();
});

test('opening it is a traceable read that needs the church settings and the closures permission', function () {
    closeOctoberOfferings();

    $this->get(route('treasury.monthly-offering-closures.pdf', '2026-10'))->assertOk();
    expect(ActivityLogEntry::query()->where('action', 'monthly_offering_closure.pdf_downloaded')->count())->toBe(1);

    ChurchSetting::query()->delete();
    $this->get(route('treasury.monthly-offering-closures.pdf', '2026-10'))->assertStatus(409);

    signInAs(UserRole::Member);
    $this->get(route('treasury.monthly-offering-closures.pdf', '2026-10'))->assertForbidden();
});
