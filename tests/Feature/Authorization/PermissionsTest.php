<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

test('answers each permission through the Gate according to the role', function (UserRole $role, string $ability, bool $allowed) {
    $user = User::factory()->role($role)->create();

    expect(Gate::forUser($user)->allows($ability))->toBe($allowed);
})->with([
    'treasurer manages offerings' => [UserRole::Treasurer, 'offerings.manage', true],
    'treasurer does not view members' => [UserRole::Treasurer, 'members.view', false],
    'treasurer is kept out of audits' => [UserRole::Treasurer, 'audits.view', false],
    'secretary manages members' => [UserRole::Secretary, 'members.manage', true],
    'secretary does not touch money' => [UserRole::Secretary, 'offerings.view', false],
    'comptroller records audits' => [UserRole::Comptroller, 'audits.record', true],
    'comptroller cannot change money' => [UserRole::Comptroller, 'offerings.manage', false],
    'assistant views members' => [UserRole::Assistant, 'members.view', true],
    'assistant cannot manage members' => [UserRole::Assistant, 'members.manage', false],
    'member sees no dashboard' => [UserRole::Member, 'dashboard.view', false],
    'pastor sees tithes' => [UserRole::Pastor, 'tithes.view', true],
]);

test('lets the pastor do anything, even what no permission names', function () {
    $pastor = User::factory()->role(UserRole::Pastor)->create();

    expect(Gate::forUser($pastor)->allows('an-ability-nobody-defined'))->toBeTrue();
});

test('denies undefined abilities to everyone but the pastor', function () {
    $secretary = User::factory()->role(UserRole::Secretary)->create();

    expect(Gate::forUser($secretary)->allows('an-ability-nobody-defined'))->toBeFalse();
});

test('denies everything to a deactivated pastor', function () {
    $pastor = User::factory()->role(UserRole::Pastor)->inactive()->create();

    expect(Gate::forUser($pastor)->allows('dashboard.view'))->toBeFalse();
    expect(Gate::forUser($pastor)->allows('users.manage'))->toBeFalse();
});

test('shares the permissions of the account with the frontend', function () {
    $treasurer = User::factory()->role(UserRole::Treasurer)->create();

    $response = $this->actingAs($treasurer)->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('auth.permissions', fn ($permissions) => collect($permissions)->contains('offerings.manage')
            && ! collect($permissions)->contains('members.view')));
});

test('shares no permissions for a regular member', function () {
    $member = User::factory()->role(UserRole::Member)->create();

    $response = $this->actingAs($member)->get(route('profile.edit'));

    $response->assertInertia(fn (Assert $page) => $page->where('auth.permissions', []));
});

test('shares no permissions with guests', function () {
    $response = $this->get(route('login'));

    $response->assertInertia(fn (Assert $page) => $page->where('auth.permissions', []));
});
