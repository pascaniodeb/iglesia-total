<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Ramsey\Uuid\Uuid;

afterEach(fn () => dropLegacySource());

test('imports accounts with their role, active flag and member link', function () {
    createLegacySource();
    legacyMember(7);
    legacyUser(1, ['role' => 'pastor', 'is_active' => false, 'member_id' => 7]);

    $this->artisan('members:import-legacy')->assertSuccessful();
    $this->artisan('users:import-legacy')->assertSuccessful();

    $user = User::firstOrFail();

    expect($user->legacy_id)->toBe(1);
    expect($user->email)->toBe('legacy1@example.com');
    expect($user->role)->toBe(UserRole::Pastor);
    expect($user->is_active)->toBeFalse();
    expect($user->member->legacy_id)->toBe(7);
});

test('builds each account UUIDv7 from the legacy creation time', function () {
    createLegacySource();
    legacyUser(1, ['created_at' => '2026-03-15 10:30:00+00']);

    $this->artisan('users:import-legacy')->assertSuccessful();

    $uuid = Uuid::fromString(User::firstOrFail()->id);

    expect($uuid->getVersion())->toBe(7);
    expect($uuid->getDateTime()->format('Y-m-d H:i:s'))->toBe('2026-03-15 10:30:00');
});

test('marks the email as verified only for verified legacy accounts', function () {
    createLegacySource();
    legacyUser(1, ['email_verified' => true, 'updated_at' => '2026-03-16 08:00:00+00']);
    legacyUser(2, ['email_verified' => false]);

    $this->artisan('users:import-legacy')->assertSuccessful();

    $verified = User::where('legacy_id', 1)->firstOrFail();
    $unverified = User::where('legacy_id', 2)->firstOrFail();

    expect($verified->email_verified_at->utc()->toDateTimeString())->toBe('2026-03-16 08:00:00');
    expect($unverified->email_verified_at)->toBeNull();
});

test('leaves the member link empty and warns when the member was not imported', function () {
    createLegacySource();
    legacyUser(1, ['member_id' => 99]);

    $this->artisan('users:import-legacy')
        ->expectsOutputToContain('member 99 was not found')
        ->assertSuccessful();

    expect(User::firstOrFail()->member_id)->toBeNull();
});

test('does not give imported accounts a password that works', function () {
    createLegacySource();
    legacyUser(1);

    $this->artisan('users:import-legacy')->assertSuccessful();

    $this->post(route('login.store'), [
        'email' => 'legacy1@example.com',
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('keeps the password of an existing account on a second run', function () {
    createLegacySource();
    legacyUser(1, ['name' => 'Old Name']);
    $this->artisan('users:import-legacy')->assertSuccessful();
    $user = User::firstOrFail();
    $user->update(['password' => 'a-password-set-here']);
    $idAfterFirstRun = $user->id;

    DB::connection('legacy')->table('users')->where('id', 1)->update(['name' => 'New Name']);
    $this->artisan('users:import-legacy')->assertSuccessful();

    $user = User::firstOrFail();

    expect(User::count())->toBe(1);
    expect($user->id)->toBe($idAfterFirstRun);
    expect($user->name)->toBe('New Name');
    expect(Hash::check('a-password-set-here', $user->password))->toBeTrue();
});

test('writes nothing on a dry run', function () {
    createLegacySource();
    legacyUser(1);

    $this->artisan('users:import-legacy', ['--dry-run' => true])->assertSuccessful();

    expect(User::count())->toBe(0);
});

test('maps the legacy role values to the new roles', function (string $legacy, UserRole $expected) {
    createLegacySource();
    legacyUser(1, ['role' => $legacy]);

    $this->artisan('users:import-legacy')->assertSuccessful();

    expect(User::firstOrFail()->role)->toBe($expected);
})->with([
    'pastor' => ['pastor', UserRole::Pastor],
    'secretaria' => ['secretaria', UserRole::Secretary],
    'tesoreria' => ['tesoreria', UserRole::Treasurer],
    'contraloria' => ['contraloria', UserRole::Comptroller],
    'miembro' => ['miembro', UserRole::Member],
]);

test('fails without writing anything when a legacy role is unknown', function () {
    createLegacySource();
    legacyUser(1, ['role' => 'pastor']);
    legacyUser(2, ['role' => 'administrador']);

    $this->artisan('users:import-legacy')
        ->expectsOutputToContain('Unknown legacy role [administrador].')
        ->assertFailed();

    expect(User::count())->toBe(0);
});
