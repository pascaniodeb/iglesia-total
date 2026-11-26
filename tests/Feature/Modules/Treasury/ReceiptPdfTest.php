<?php

use App\Enums\UserRole;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Church\Infrastructure\Models\ChurchSetting;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(function () {
    Carbon::setTestNow('2026-11-16 14:00:00');
    $this->income = AccountingAccount::factory()->create(['code' => 'I-007', 'name' => 'APORTES']);
    ChurchSetting::factory()->create();
    signInAs(UserRole::Treasurer);
    $this->post(route('treasury.receipts.store'), [
        'type' => 'income', 'issued_on' => '2026-11-10', 'account_id' => $this->income->id, 'holder' => 'María Pérez',
        'amount_cop' => '141000', 'concept' => 'Aporte para el templo', 'payment_method' => 'cash', 'token' => (string) Str::uuid7(),
    ])->assertRedirect();
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * The text of the page as a person would read it: the PDF streams are not compressed in the test
 * (FPDF compresses them, so inflate each one and keep the text operators).
 */
function pdfText(string $pdf): string
{
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
    $text = '';

    foreach ($streams[1] as $stream) {
        $content = @gzuncompress($stream);
        preg_match_all('/\((.*?)\)\s*Tj/s', $content === false ? $stream : $content, $found);
        $text .= implode(' ', $found[1]).' ';
    }

    return mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
}

test('the receipt comes out as a half-letter PDF with what the paper says', function () {
    $response = $this->get(route('treasury.receipts.pdf', 1))->assertOk();
    $pdf = $response->getContent();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('comprobante-000001.pdf')
        ->and($pdf)->toStartWith('%PDF')
        ->and($pdf)->toContain('/MediaBox [0 0 612.00 396.00]')
        ->and(pdfText($pdf))->toContain('COMPROBANTE')
        ->and(pdfText($pdf))->toContain('N° 000001')
        ->and(pdfText($pdf))->toContain('CIENTO CUARENTA Y UN MIL PESOS COLOMBIANOS CON 00/100')
        ->and(pdfText($pdf))->toContain('141.000,00')
        ->and(pdfText($pdf))->toContain('María Pérez')
        ->and(pdfText($pdf))->not->toContain('ANULADO');
});

test('a voided receipt carries its band and reason', function () {
    signInAs(UserRole::Pastor);
    $this->post(route('treasury.receipts.cancel', 1), ['reason' => 'Error de captura'])->assertRedirect();

    $text = pdfText($this->get(route('treasury.receipts.pdf', 1))->getContent());

    expect($text)->toContain('ANULADO')->and($text)->toContain('Error de captura');
});

test('opening the PDF is a traceable read', function () {
    $this->get(route('treasury.receipts.pdf', 1))->assertOk();

    expect(ActivityLogEntry::query()->where('action', 'receipt.pdf_downloaded')->count())->toBe(1);
});

test('the PDF is not drawn before the church has settings', function () {
    ChurchSetting::query()->delete();

    $this->get(route('treasury.receipts.pdf', 1))->assertStatus(409);
});

test('a receipt that does not exist is a 404, and whoever cannot read the books is refused', function () {
    $this->get(route('treasury.receipts.pdf', 99))->assertNotFound();

    signInAs(UserRole::Member);

    $this->get(route('treasury.receipts.pdf', 1))->assertForbidden();
});
