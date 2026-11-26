<?php

use App\Enums\UserRole;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Church\Infrastructure\Models\ChurchSetting;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Shared\Time\IsoWeek;
use App\Modules\Treasury\Application\Actions\CloseWeek;
use App\Modules\Treasury\Infrastructure\Models\Offering;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use Illuminate\Support\Carbon;

beforeEach(function () {
    // Friday 6 November 2026: week 2026-W44 (26 Oct - 1 Nov) has ended.
    Carbon::setTestNow('2026-11-06 14:00:00');
    signInAs(UserRole::Treasurer);
    ChurchSetting::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @param  array<string, string>  $amounts
 */
function weekOffering(OfferingCategory $category, string $date, array $amounts): void
{
    Offering::factory()->create(['offering_date' => $date, 'offering_category_id' => $category->id, 'amount_ves' => '0.00', 'amount_usd' => '0.00', 'amount_cop' => '0.00', ...$amounts]);
}

function categoryWithCode(string $code, string $name): OfferingCategory
{
    return OfferingCategory::factory()->create(['name' => $name, 'account_id' => AccountingAccount::factory()->create(['code' => $code, 'name' => $name])->id]);
}

test('the closed week prints one line per category with its code and the closure totals', function () {
    $daily = categoryWithCode('I-002', 'OFRENDA DIARIA');
    $special = categoryWithCode('I-004', 'OFRENDA ESPECIAL');
    weekOffering($daily, '2026-10-28', ['amount_cop' => '100000.50', 'amount_usd' => '5.00']);
    weekOffering($daily, '2026-11-01', ['amount_cop' => '40000.00']);
    weekOffering($special, '2026-11-01', ['amount_ves' => '250.25']);
    app(CloseWeek::class)(IsoWeek::fromKey('2026-W44'));

    $response = $this->get(route('treasury.weekly-closures.pdf', '2026-W44'))->assertOk();
    $text = pdfText($response->getContent());

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->getContent())->toContain('/MediaBox [0 0 792.00 612.00]')
        ->and($text)->toContain('REPORTE DE INGRESOS SEMANAL')
        ->and($text)->toContain('I-002')
        ->and($text)->toContain('OFRENDA DIARIA')
        ->and($text)->toContain('140.000,50')
        ->and($text)->toContain('I-004')
        ->and($text)->toContain('250,25')
        ->and($text)->toContain('TOTALES GENERALES:')
        ->and($text)->toContain('26/10/2026')
        ->and($text)->toContain('PASTOR')
        ->and($text)->toContain('Semana aún sin sellar');
});

test('more categories than lines go into "OTROS INGRESOS"', function () {
    foreach (range(1, 9) as $i) {
        weekOffering(categoryWithCode(sprintf('I-%03d', $i + 10), "CATEGORIA {$i}"), '2026-10-28', ['amount_cop' => '10.00']);
    }
    app(CloseWeek::class)(IsoWeek::fromKey('2026-W44'));

    $text = pdfText($this->get(route('treasury.weekly-closures.pdf', '2026-W44'))->getContent());

    expect($text)->toContain('OTROS INGRESOS')
        // 9 categories of 10,00: seven on their own line and two (20,00) gathered; the total is 90,00.
        ->and($text)->toContain('20,00')
        ->and($text)->toContain('90,00')
        ->and($text)->not->toContain('CATEGORIA 9');
});

test('a week that is not closed has no form, and a malformed week does not exist', function () {
    $this->get(route('treasury.weekly-closures.pdf', '2026-W44'))->assertStatus(409);
    $this->get('/treasury/weekly-closures/2026-W99/pdf')->assertNotFound();
    $this->get('/treasury/weekly-closures/abc/pdf')->assertNotFound();
});

test('opening the form is a traceable read, it needs the church settings and the closures permission', function () {
    weekOffering(categoryWithCode('I-002', 'OFRENDA DIARIA'), '2026-10-28', ['amount_cop' => '10.00']);
    app(CloseWeek::class)(IsoWeek::fromKey('2026-W44'));

    $this->get(route('treasury.weekly-closures.pdf', '2026-W44'))->assertOk();
    expect(ActivityLogEntry::query()->where('action', 'weekly_closure.pdf_downloaded')->count())->toBe(1);

    ChurchSetting::query()->delete();
    $this->get(route('treasury.weekly-closures.pdf', '2026-W44'))->assertStatus(409);

    signInAs(UserRole::Member);
    $this->get(route('treasury.weekly-closures.pdf', '2026-W44'))->assertForbidden();
});
