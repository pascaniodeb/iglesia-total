<?php

use App\Modules\Treasury\Domain\Receipts\PaymentMethod;
use App\Modules\Treasury\Domain\Receipts\ReceiptContent;
use App\Modules\Treasury\Domain\Receipts\ReceiptNumber;
use App\Modules\Treasury\Domain\Receipts\ReceiptType;

function sampleReceiptContent(array $changes = []): ReceiptContent
{
    return new ReceiptContent(...[
        'number' => 7,
        'type' => ReceiptType::Income,
        'issuedOn' => '2026-11-10',
        'accountId' => '0198f3a0-0000-7000-8000-000000000001',
        'holder' => 'María Pérez',
        'counterparty' => null,
        'amountVes' => '0.00',
        'amountUsd' => '25.50',
        'amountCop' => '100000.00',
        'concept' => 'Aporte para el templo',
        'paymentMethod' => PaymentMethod::Transfer,
        'bank' => 'Banesco',
        'paymentReference' => '00123456',
        'paymentDate' => '2026-11-09',
        'receivedByName' => 'Pablo Ascanio',
        'receivedByIdNumber' => 'V-1234567',
        'deliveredByName' => null,
        'deliveredByIdNumber' => null,
        ...$changes,
    ]);
}

test('numbers show as the printed book does and never lose digits', function (int $number, string $shown) {
    expect(ReceiptNumber::format($number))->toBe($shown);
})->with([
    [1, '000001'],
    [100, '000100'],
    [999999, '999999'],
    [1000000, '1000000'],
]);

test('a number below one is refused', function () {
    expect(fn () => ReceiptNumber::format(0))->toThrow(InvalidArgumentException::class);
});

test('the next number follows the highest, never below the start of the book', function (?int $highest, int $start, int $next) {
    expect(ReceiptNumber::next($highest, $start))->toBe($next);
})->with([
    'first receipt of the book' => [null, 1, 1],
    'first receipt of a later book' => [null, 500, 500],
    'after the highest' => [7, 1, 8],
    'a new book begins above the highest' => [7, 500, 500],
    'lowering the start never reuses a number' => [7, 3, 8],
]);

// Checked against an independent computation of the canonical JSON (sorted keys, no spaces,
// amounts as text) and its SHA-256, not copied from the code under test.
test('the hash of a receipt is pinned: changing it would change every sealed month', function () {
    expect(sampleReceiptContent()->hash())->toBe('63019cf67d079e0a77b7580028eb6546e9b950be2d0859e0c9cfbb40d22ce4c2');
});

test('every field of what the paper says moves the hash', function (string $field, mixed $value) {
    expect(sampleReceiptContent([$field => $value])->hash())->not->toBe(sampleReceiptContent()->hash());
})->with([
    ['number', 8],
    ['type', ReceiptType::Expense],
    ['issuedOn', '2026-11-11'],
    ['accountId', '0198f3a0-0000-7000-8000-000000000002'],
    ['holder', 'María Pereira'],
    ['counterparty', 'Otra persona'],
    ['amountVes', '1.00'],
    ['amountUsd', '25.51'],
    ['amountCop', '100000.01'],
    ['concept', 'Otro concepto'],
    ['paymentMethod', PaymentMethod::Cash],
    ['bank', 'Mercantil'],
    ['paymentReference', '00123457'],
    ['paymentDate', '2026-11-08'],
    ['receivedByName', 'Otro'],
    ['receivedByIdNumber', 'V-7654321'],
    ['deliveredByName', 'Alguien'],
    ['deliveredByIdNumber', 'V-1111111'],
]);

test('an empty field and a missing field are the same thing', function () {
    expect(sampleReceiptContent(['counterparty' => null])->hash())->toBe(sampleReceiptContent()->hash());
});
