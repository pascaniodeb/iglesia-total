<?php

use App\Enums\UserRole;
use App\Modules\Church\Infrastructure\Models\ChurchSetting;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Members\Infrastructure\Models\Member;
use App\Modules\Treasury\Infrastructure\Models\Tithe;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-11-16 14:00:00');
    ChurchSetting::factory()->create();
    signInAs(UserRole::Pastor);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @param  array<string, string>  $amounts
 */
function pdfTithe(string $name, string $date, array $amounts): void
{
    Tithe::factory()->create([
        'member_id' => Member::factory()->create(['full_name' => $name])->id,
        'tithe_date' => $date,
        'amount_ves' => '0', 'amount_usd' => '0', 'amount_cop' => '0',
        ...$amounts,
    ]);
}

test('the tithes report prints who tithed, in alphabetical order, with the totals to the cent', function () {
    pdfTithe('Zoila Pérez', '2026-08-02', ['amount_cop' => '50000.10']);
    pdfTithe('Ana Gómez', '2026-08-09', ['amount_usd' => '10.00', 'amount_cop' => '25000.20']);

    $response = $this->get(route('treasury.tithes.reports.pdf', ['granularity' => 'month', 'period' => '2026-08']))->assertOk();
    $text = pdfText($response->getContent());

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($text)->toContain('REPORTE DE DIEZMOS - AGOSTO DE 2026')
        ->and($text)->toContain('Ana Gómez')
        ->and($text)->toContain('Zoila Pérez')
        ->and(strpos($text, 'Ana Gómez'))->toBeLessThan(strpos($text, 'Zoila Pérez'))
        ->and($text)->toContain('75.000,30')
        ->and($text)->toContain('Documento confidencial')
        ->and($text)->not->toContain('Cadena sellada');
});

test('a period nobody tithed in says so', function () {
    $text = pdfText($this->get(route('treasury.tithes.reports.pdf', ['granularity' => 'month', 'period' => '2026-08']))->getContent());

    expect($text)->toContain('NADIE DIEZMÓ EN ESTE PERÍODO');
});

test('only whoever may read the tithes book gets the PDF, and the read is traced', function () {
    $this->get(route('treasury.tithes.reports.pdf'))->assertOk();
    expect(ActivityLogEntry::query()->where('action', 'tithe_report.pdf_downloaded')->count())->toBe(1);

    signInAs(UserRole::Treasurer);
    $this->get(route('treasury.tithes.reports.pdf'))->assertForbidden();

    signInAs(UserRole::Pastor);
    ChurchSetting::query()->delete();
    $this->get(route('treasury.tithes.reports.pdf'))->assertStatus(409);
});
