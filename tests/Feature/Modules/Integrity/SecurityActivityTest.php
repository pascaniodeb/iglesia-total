<?php

use App\Actions\Users\ChangeUserRole;
use App\Enums\UserRole;
use App\Models\User;
use App\Modules\Integrity\Domain\Enums\ActivityCategory;
use App\Modules\Integrity\Domain\Enums\ActivityChannel;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Members\Infrastructure\Models\Member;

test('signing in and out is recorded as a security fact of that account', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect();
    $this->post(route('logout'))->assertRedirect();

    $entries = ActivityLogEntry::query()->orderBy('seq')->get();

    expect($entries->pluck('action')->all())->toBe(['auth.login', 'auth.logout'])
        ->and($entries->every(fn (ActivityLogEntry $entry) => $entry->category === ActivityCategory::Security))->toBeTrue()
        ->and($entries->every(fn (ActivityLogEntry $entry) => $entry->subject_id === $user->id))->toBeTrue();
});

test('a failed sign-in never records the typed email or password', function () {
    $user = User::factory()->create(['email' => 'rosa@example.com']);

    $this->post(route('login.store'), ['email' => 'rosa@example.com', 'password' => 'wrong-secret']);
    $this->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'wrong-secret']);

    $entries = ActivityLogEntry::query()->where('action', 'auth.failed')->orderBy('seq')->get();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->subject_id)->toBe($user->id)
        ->and($entries[1]->subject_id)->toBeNull()
        ->and($entries[0]->channel)->toBe(ActivityChannel::Guest);

    $raw = ActivityLogEntry::query()->get()->toJson();
    expect($raw)->not->toContain('wrong-secret')->not->toContain('nobody@example.com');
});

test('changing a role is recorded with the previous and the new role', function () {
    $pastor = User::factory()->create();
    $pastor->forceFill(['role' => UserRole::Pastor])->save();
    $user = User::factory()->create(['name' => 'Rosa']);
    $user->forceFill(['role' => UserRole::Secretary])->save();

    app(ChangeUserRole::class)($user, UserRole::Treasurer, $pastor);

    $entry = ActivityLogEntry::query()->sole();

    expect($entry->action)->toBe('user.role_changed')
        ->and($entry->category)->toBe(ActivityCategory::Security)
        ->and($entry->changes['role'])->toEqual(['from' => 'secretario', 'to' => 'tesorero'])
        ->and($entry->summary)->toBe('Cambió el rol de Rosa de Secretario a Tesorero.');
});

test('setting the same role again changes nothing and records nothing', function () {
    $user = User::factory()->create();
    $user->forceFill(['role' => UserRole::Secretary])->save();

    app(ChangeUserRole::class)($user, UserRole::Secretary);

    expect(ActivityLogEntry::query()->count())->toBe(0);
});

test('changing the own password is recorded without the password', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'New-Secret-Password-2026!',
            'password_confirmation' => 'New-Secret-Password-2026!',
        ])
        ->assertSessionHasNoErrors();

    $entry = ActivityLogEntry::query()->where('action', 'auth.password_changed')->sole();

    expect($entry->actor_id)->toBe($user->id)
        ->and(ActivityLogEntry::query()->get()->toJson())->not->toContain('New-Secret-Password-2026!');
});

test('the operator setting a password from the console is recorded as the system', function () {
    $member = Member::factory()->create();
    $user = User::factory()->create(['email' => 'rosa@example.com']);
    $user->forceFill(['member_id' => $member->id])->save();

    $this->artisan('users:set-password', ['email' => 'rosa@example.com', '--generate' => true])->assertSuccessful();

    $entry = ActivityLogEntry::query()->sole();

    expect($entry->action)->toBe('user.password_set')
        ->and($entry->channel)->toBe(ActivityChannel::System)
        ->and($entry->subject_id)->toBe($user->id);
});
