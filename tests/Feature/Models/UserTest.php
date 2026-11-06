<?php

use App\Enums\UserRole;
use App\Models\Member;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Ramsey\Uuid\Uuid;

test('assigns a UUIDv7 as the primary key', function () {
    $user = User::factory()->create();

    expect($user->id)->toBeUuid();
    expect(Uuid::fromString($user->id)->getVersion())->toBe(7);
});

test('creates accounts as active members by default', function () {
    $user = User::factory()->create();

    $user->refresh();

    expect($user->role)->toBe(UserRole::Member);
    expect($user->is_active)->toBeTrue();
});

test('ignores role, active flag and member in mass assignment', function () {
    $member = Member::factory()->create();

    $user = User::create([
        'name' => 'Escalation Attempt',
        'email' => 'attempt@example.com',
        'password' => 'password',
        'role' => 'pastor',
        'is_active' => false,
        'member_id' => $member->id,
    ]);

    $user->refresh();

    expect($user->role)->toBe(UserRole::Member);
    expect($user->is_active)->toBeTrue();
    expect($user->member_id)->toBeNull();
});

test('registers new accounts with the least privileged role', function () {
    $this->post(route('register.store'), [
        'name' => 'New Person',
        'email' => 'new@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => 'pastor',
    ]);

    $user = User::where('email', 'new@example.com')->firstOrFail();

    expect($user->role)->toBe(UserRole::Member);
});

test('belongs to the member of the registry it is linked to', function () {
    $member = Member::factory()->create();

    $user = User::factory()->forMember($member)->create();

    expect($user->member->is($member))->toBeTrue();
});

test('rejects two accounts for the same member', function () {
    $member = Member::factory()->create();
    User::factory()->forMember($member)->create();

    expect(fn () => User::factory()->forMember($member)->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

test('rejects permanently deleting a member that has an account', function () {
    $member = Member::factory()->create();
    User::factory()->forMember($member)->create();

    expect(fn () => $member->forceDelete())->toThrow(QueryException::class);
});

test('allows soft deleting a member that has an account', function () {
    $member = Member::factory()->create();
    $user = User::factory()->forMember($member)->create();

    $member->delete();

    expect($user->refresh()->member_id)->toBe($member->id);
});
