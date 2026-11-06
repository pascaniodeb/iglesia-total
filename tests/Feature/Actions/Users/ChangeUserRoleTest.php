<?php

use App\Actions\Users\ChangeUserRole;
use App\Enums\UserRole;
use App\Models\User;
use App\Modules\Members\Infrastructure\Models\Member;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

test('changes the role of an account', function () {
    $user = User::factory()->role(UserRole::Treasurer)->create();

    app(ChangeUserRole::class)($user, UserRole::Secretary);

    expect($user->refresh()->role)->toBe(UserRole::Secretary);
});

test('lets a pastor change the role of another account', function () {
    $pastor = User::factory()->role(UserRole::Pastor)->create();
    $user = User::factory()->role(UserRole::Member)->forMember(Member::factory()->create())->create();

    app(ChangeUserRole::class)($user, UserRole::Assistant, $pastor);

    expect($user->refresh()->role)->toBe(UserRole::Assistant);
});

test('forbids actors who cannot manage users', function (UserRole $role) {
    $actor = User::factory()->role($role)->create();
    $user = User::factory()->role(UserRole::Member)->create();

    expect(fn () => app(ChangeUserRole::class)($user, UserRole::Pastor, $actor))
        ->toThrow(AuthorizationException::class);
    expect($user->refresh()->role)->toBe(UserRole::Member);
})->with([
    'assistant' => UserRole::Assistant,
    'secretary' => UserRole::Secretary,
    'treasurer' => UserRole::Treasurer,
    'comptroller' => UserRole::Comptroller,
    'member' => UserRole::Member,
]);

test('rejects an actor changing their own role', function () {
    $pastor = User::factory()->role(UserRole::Pastor)->create();
    User::factory()->role(UserRole::Pastor)->create();

    expect(fn () => app(ChangeUserRole::class)($pastor, UserRole::Secretary, $pastor))
        ->toThrow(ValidationException::class, 'No puede cambiar su propio rol.');
    expect($pastor->refresh()->role)->toBe(UserRole::Pastor);
});

test('rejects demoting the last active pastor', function () {
    $pastor = User::factory()->role(UserRole::Pastor)->create();

    expect(fn () => app(ChangeUserRole::class)($pastor, UserRole::Secretary))
        ->toThrow(ValidationException::class, 'Debe haber al menos un pastor activo.');
    expect($pastor->refresh()->role)->toBe(UserRole::Pastor);
});

test('does not count deactivated pastors when protecting the last one', function () {
    $pastor = User::factory()->role(UserRole::Pastor)->create();
    User::factory()->role(UserRole::Pastor)->inactive()->create();

    expect(fn () => app(ChangeUserRole::class)($pastor, UserRole::Secretary))
        ->toThrow(ValidationException::class, 'Debe haber al menos un pastor activo.');
});

test('allows demoting a pastor while another active pastor remains', function () {
    $pastor = User::factory()->role(UserRole::Pastor)->create();
    User::factory()->role(UserRole::Pastor)->create();

    app(ChangeUserRole::class)($pastor, UserRole::Secretary);

    expect($pastor->refresh()->role)->toBe(UserRole::Secretary);
});

test('requires a linked member for a member account', function () {
    $user = User::factory()->role(UserRole::Treasurer)->create();

    expect(fn () => app(ChangeUserRole::class)($user, UserRole::Member))
        ->toThrow(ValidationException::class, 'Una cuenta de miembro debe estar vinculada a un miembro del padrón.');
    expect($user->refresh()->role)->toBe(UserRole::Treasurer);
});

test('accepts the member role for an account linked to a member', function () {
    $user = User::factory()->role(UserRole::Treasurer)->forMember(Member::factory()->create())->create();

    app(ChangeUserRole::class)($user, UserRole::Member);

    expect($user->refresh()->role)->toBe(UserRole::Member);
});
