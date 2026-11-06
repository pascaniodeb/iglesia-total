<?php

use App\Enums\UserRole;
use App\Models\User;

test('lets active accounts through', function () {
    $user = User::factory()->role(UserRole::Secretary)->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

test('ends the session of a deactivated account and sends it to the login page', function () {
    $user = User::factory()->inactive()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertRedirect(route('login'));
    $this->assertGuest();
});

test('explains why a deactivated account was signed out', function () {
    $user = User::factory()->inactive()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertInertiaFlash('toast', [
        'type' => 'error',
        'message' => 'Su cuenta está desactivada. Comuníquese con la secretaría de la iglesia.',
    ]);
});

test('keeps a deactivated account out even with valid credentials', function () {
    $user = User::factory()->inactive()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);
    $this->get(route('dashboard'))->assertRedirect(route('login'));

    $this->assertGuest();
});

test('signs out an account that was deactivated while its session was open', function () {
    $user = User::factory()->role(UserRole::Secretary)->create();
    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $user->forceFill(['is_active' => false])->save();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});
