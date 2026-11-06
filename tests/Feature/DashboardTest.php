<?php

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->role(UserRole::Secretary)->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('every role with the dashboard permission can visit the dashboard', function (UserRole $role) {
    $user = User::factory()->role($role)->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
})->with(array_filter(
    array_column(UserRole::cases(), null, 'value'),
    fn (UserRole $role) => $role->grants(Permission::DashboardView),
));

test('members without dashboard access land on their own profile', function () {
    $user = User::factory()->role(UserRole::Member)->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertRedirect(route('profile.edit'));
});
