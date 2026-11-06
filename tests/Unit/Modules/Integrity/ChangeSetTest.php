<?php

use App\Modules\Integrity\Domain\ChangeSet;
use App\Modules\Members\Domain\Enums\MemberStatus;

test('lists only the fields that changed, in a stable order', function () {
    $changes = ChangeSet::between(
        ['full_name' => 'Ana', 'status' => 'activo', 'profession' => 'Docente'],
        ['full_name' => 'Ana María', 'status' => 'activo', 'profession' => 'Docente'],
    );

    expect($changes)->toBe(['full_name' => ['from' => 'Ana', 'to' => 'Ana María']]);
});

test('a new record lists its filled fields as changed from nothing', function () {
    $changes = ChangeSet::between([], ['status' => 'activo', 'full_name' => 'Ana', 'occupation' => null]);

    expect($changes)->toBe([
        'full_name' => ['from' => null, 'to' => 'Ana'],
        'status' => ['from' => null, 'to' => 'activo'],
    ]);
});

test('never logs the value of a personal field, only that it changed', function () {
    $changes = ChangeSet::between(
        ['document_id' => '12345678', 'phone' => null],
        ['document_id' => '87654321', 'phone' => '0414-1234567'],
        sensitive: ['document_id', 'phone'],
    );

    expect($changes)->toBe([
        'document_id' => ['from' => ChangeSet::REDACTED, 'to' => ChangeSet::REDACTED],
        'phone' => ['from' => null, 'to' => ChangeSet::REDACTED],
    ])->and(json_encode($changes))->not->toContain('12345678')->not->toContain('0414');
});

test('ignores the fields that are not part of the change', function () {
    $changes = ChangeSet::between(
        ['full_name' => 'Ana', 'updated_at' => '2026-11-01 10:00:00'],
        ['full_name' => 'Ana', 'updated_at' => '2026-11-06 10:00:00'],
        ignored: ['updated_at'],
    );

    expect($changes)->toBe([]);
});

test('compares enums, dates and empty strings by meaning', function () {
    $changes = ChangeSet::between(
        ['status' => MemberStatus::Active, 'birth_date' => new DateTimeImmutable('2000-01-02'), 'email' => ''],
        ['status' => 'activo', 'birth_date' => '2000-01-02', 'email' => null],
    );

    expect($changes)->toBe([]);
});
