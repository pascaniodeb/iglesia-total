<?php

use App\Modules\Members\Infrastructure\Models\Member;
use App\Modules\Members\Infrastructure\Models\MemberChild;
use App\Modules\Members\Infrastructure\Models\MemberRole;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

afterEach(fn () => dropLegacySource());

test('imports members, children and roles linked through new UUIDs', function () {
    createLegacySource();
    legacyMember(7, ['full_name' => 'Ángela Núñez', 'sex' => 'femenino', 'status' => 'activo', 'birth_date' => '1990-05-20']);
    DB::connection('legacy')->table('member_children')->insert(
        ['id' => 1, 'member_id' => 7, 'name' => 'Sofía', 'birth_date' => '2018-01-02', 'created_at' => '2026-03-15 11:00:00+00'],
    );
    DB::connection('legacy')->table('member_roles')->insert(
        ['id' => 1, 'member_id' => 7, 'role' => 'Diácono', 'since' => '2024-01-01', 'until' => null, 'created_at' => '2026-03-15 11:00:00+00'],
    );

    $this->artisan('members:import-legacy')->assertSuccessful();

    $member = Member::firstOrFail();

    expect($member->legacy_id)->toBe(7);
    expect($member->full_name)->toBe('Ángela Núñez');
    expect($member->sex->value)->toBe('femenino');
    expect($member->birth_date->toDateString())->toBe('1990-05-20');
    expect($member->children->pluck('name')->all())->toBe(['Sofía']);
    expect($member->roles->pluck('role')->all())->toBe(['Diácono']);
});

test('builds each UUIDv7 from the legacy creation time', function () {
    createLegacySource();
    legacyMember(7, ['created_at' => '2026-03-15 10:30:00+00']);

    $this->artisan('members:import-legacy')->assertSuccessful();

    $uuid = Uuid::fromString(Member::firstOrFail()->id);

    expect($uuid->getVersion())->toBe(7);
    expect($uuid->getDateTime()->format('Y-m-d H:i:s'))->toBe('2026-03-15 10:30:00');
});

test('keeps the legacy timestamps and soft deletes', function () {
    createLegacySource();
    legacyMember(7, [
        'created_at' => '2026-03-15 10:30:00+00',
        'updated_at' => '2026-03-16 08:00:00+00',
        'deleted_at' => '2026-04-01 09:00:00+00',
    ]);

    $this->artisan('members:import-legacy')->assertSuccessful();

    $member = Member::withTrashed()->firstOrFail();

    expect($member->trashed())->toBeTrue();
    expect($member->created_at->utc()->toDateTimeString())->toBe('2026-03-15 10:30:00');
    expect($member->updated_at->utc()->toDateTimeString())->toBe('2026-03-16 08:00:00');
    expect($member->deleted_at->utc()->toDateTimeString())->toBe('2026-04-01 09:00:00');
});

test('links spouses in both directions with the new UUIDs', function () {
    createLegacySource();
    legacyMember(1, ['spouse_id' => 2]);
    legacyMember(2, ['spouse_id' => 1]);

    $this->artisan('members:import-legacy')->assertSuccessful();

    $first = Member::where('legacy_id', 1)->firstOrFail();
    $second = Member::where('legacy_id', 2)->firstOrFail();

    expect($first->spouse_id)->toBe($second->id);
    expect($second->spouse_id)->toBe($first->id);
});

test('refreshes existing members on a second run without duplicating them', function () {
    createLegacySource();
    legacyMember(7, ['full_name' => 'Old Name']);
    DB::connection('legacy')->table('member_children')->insert(
        ['id' => 1, 'member_id' => 7, 'name' => 'Sofía', 'birth_date' => null, 'created_at' => '2026-03-15 11:00:00+00'],
    );
    $this->artisan('members:import-legacy')->assertSuccessful();
    $idAfterFirstRun = Member::firstOrFail()->id;

    DB::connection('legacy')->table('members')->where('id', 7)->update(['full_name' => 'New Name']);
    $this->artisan('members:import-legacy')->assertSuccessful();

    expect(Member::count())->toBe(1);
    expect(MemberChild::count())->toBe(1);
    expect(MemberRole::count())->toBe(0);
    expect(Member::firstOrFail()->id)->toBe($idAfterFirstRun);
    expect(Member::firstOrFail()->full_name)->toBe('New Name');
});

test('writes nothing on a dry run', function () {
    createLegacySource();
    legacyMember(7);

    $this->artisan('members:import-legacy', ['--dry-run' => true])->assertSuccessful();

    expect(Member::withTrashed()->count())->toBe(0);
});
