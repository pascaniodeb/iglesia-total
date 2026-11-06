<?php

use App\Enums\MemberSex;
use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\MemberChild;
use App\Models\MemberRole;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

test('assigns a UUIDv7 as the primary key', function () {
    $member = Member::factory()->create();

    expect($member->id)->toBeUuid();
    expect(Uuid::fromString($member->id)->getVersion())->toBe(7);
});

test('generates a UUIDv7 in the database for rows inserted without Eloquent', function () {
    DB::table('members')->insert(['full_name' => 'Raw Insert']);

    $id = DB::table('members')->value('id');

    expect(Uuid::fromString($id)->getVersion())->toBe(7);
});

test('searches names ignoring accents and case', function () {
    Member::factory()->create(['full_name' => 'Ángela Núñez']);
    Member::factory()->create(['full_name' => 'Pedro Pérez']);

    $names = Member::search('NUNEZ')->pluck('full_name')->all();

    expect($names)->toBe(['Ángela Núñez']);
});

test('searches names with a partial term in the middle of the name', function () {
    Member::factory()->create(['full_name' => 'José Gregorio Hernández']);
    Member::factory()->create(['full_name' => 'Pedro Pérez']);

    $names = Member::search('gorio')->pluck('full_name')->all();

    expect($names)->toBe(['José Gregorio Hernández']);
});

test('treats wildcard characters in the search term literally', function (string $term) {
    Member::factory()->create(['full_name' => 'Ana']);
    Member::factory()->create(['full_name' => 'Beto']);

    $count = Member::search($term)->count();

    expect($count)->toBe(0);
})->with([
    'percent' => '%',
    'underscore' => '_',
    'backslash' => '\\',
]);

test('does not filter when the search term is blank', function () {
    Member::factory()->count(2)->create();

    $count = Member::search('   ')->count();

    expect($count)->toBe(2);
});

test('orders names ignoring accents', function () {
    Member::factory()->create(['full_name' => 'Beatriz']);
    Member::factory()->create(['full_name' => 'Álvaro']);
    Member::factory()->create(['full_name' => 'Aaron']);

    $names = Member::orderedByName()->pluck('full_name')->all();

    expect($names)->toBe(['Aaron', 'Álvaro', 'Beatriz']);
});

test('casts the registry fields to enums', function () {
    $member = Member::factory()->create([
        'sex' => 'femenino',
        'status' => 'activo',
    ]);

    $member->refresh();

    expect($member->sex)->toBe(MemberSex::Female);
    expect($member->status)->toBe(MemberStatus::Active);
});

test('rejects a document id that belongs to another member', function () {
    Member::factory()->create(['document_id' => '12345678']);

    expect(fn () => Member::factory()->create(['document_id' => '12345678']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('allows many members without a document id', function () {
    Member::factory()->count(2)->create(['document_id' => null]);

    expect(Member::count())->toBe(2);
});

test('rejects a status outside the registry statuses', function () {
    expect(fn () => DB::table('members')->insert(['full_name' => 'Ana', 'status' => 'unknown']))
        ->toThrow(QueryException::class);
});

test('rejects a member being their own spouse', function () {
    $member = Member::factory()->create();

    expect(fn () => $member->update(['spouse_id' => $member->id]))
        ->toThrow(QueryException::class);
});

test('rejects two members sharing the same spouse', function () {
    $spouse = Member::factory()->create();
    Member::factory()->create(['spouse_id' => $spouse->id]);

    expect(fn () => Member::factory()->create(['spouse_id' => $spouse->id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('unlinks the spouse when the other member is permanently deleted', function () {
    $spouse = Member::factory()->create();
    $member = Member::factory()->create(['spouse_id' => $spouse->id]);

    $spouse->forceDelete();

    expect($member->refresh()->spouse_id)->toBeNull();
});

test('deletes children and roles when the member is permanently deleted', function () {
    $member = Member::factory()->create();
    MemberChild::factory()->for($member)->create();
    MemberRole::factory()->for($member)->create();

    $member->forceDelete();

    expect(MemberChild::count())->toBe(0);
    expect(MemberRole::count())->toBe(0);
});

test('keeps children and roles when the member is only soft deleted', function () {
    $member = Member::factory()->create();
    MemberChild::factory()->for($member)->create();
    MemberRole::factory()->for($member)->create();

    $member->delete();

    expect(Member::count())->toBe(0);
    expect(Member::withTrashed()->count())->toBe(1);
    expect(MemberChild::count())->toBe(1);
    expect(MemberRole::count())->toBe(1);
});
