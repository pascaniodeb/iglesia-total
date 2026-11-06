<?php

use App\Enums\UserRole;
use App\Models\User;

test('sets the role of the account', function () {
    $user = User::factory()->role(UserRole::Member)->create();

    $this->artisan('users:set-role', ['email' => $user->email, 'role' => 'tesorero'])
        ->expectsOutputToContain("{$user->email} is now Tesorero.")
        ->assertSuccessful();

    expect($user->refresh()->role)->toBe(UserRole::Treasurer);
});

test('fails when no account uses the email', function () {
    $this->artisan('users:set-role', ['email' => 'nobody@example.com', 'role' => 'pastor'])
        ->expectsOutputToContain('No account uses that email.')
        ->assertFailed();
});

test('fails with the valid roles when the role is unknown', function () {
    $user = User::factory()->create();

    $this->artisan('users:set-role', ['email' => $user->email, 'role' => 'administrador'])
        ->expectsOutputToContain('Valid roles: pastor, asistente, secretario, tesorero, contralor, miembro.')
        ->assertFailed();

    expect($user->refresh()->role)->toBe(UserRole::Member);
});

test('fails and keeps the role when it would leave no active pastor', function () {
    $pastor = User::factory()->role(UserRole::Pastor)->create();

    $this->artisan('users:set-role', ['email' => $pastor->email, 'role' => 'secretario'])
        ->expectsOutputToContain('Debe haber al menos un pastor activo.')
        ->assertFailed();

    expect($pastor->refresh()->role)->toBe(UserRole::Pastor);
});
