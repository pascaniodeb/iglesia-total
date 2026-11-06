<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

/**
 * The password the command printed ("Password (shown once) ..... <password>").
 */
function generatedPasswordFrom(string $output): string
{
    preg_match('/Password \(shown once\)\s*\.*\s*(\S+)/', $output, $matches);

    return $matches[1] ?? '';
}

test('sets the password of the account', function () {
    $user = User::factory()->create();

    $this->artisan('users:set-password', ['email' => $user->email])
        ->expectsQuestion('New password', 'a-Brand-new-passw0rd')
        ->expectsQuestion('Confirm the new password', 'a-Brand-new-passw0rd')
        ->assertSuccessful();

    expect(Hash::check('a-Brand-new-passw0rd', $user->refresh()->password))->toBeTrue();
});

test('lets the account log in with the new password', function () {
    $user = User::factory()->create();
    $this->artisan('users:set-password', ['email' => $user->email])
        ->expectsQuestion('New password', 'a-Brand-new-passw0rd')
        ->expectsQuestion('Confirm the new password', 'a-Brand-new-passw0rd')
        ->assertSuccessful();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'a-Brand-new-passw0rd',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

test('fails and keeps the password when the confirmation does not match', function () {
    $user = User::factory()->create();
    $oldHash = $user->password;

    $this->artisan('users:set-password', ['email' => $user->email])
        ->expectsQuestion('New password', 'a-Brand-new-passw0rd')
        ->expectsQuestion('Confirm the new password', 'something-else-entirely')
        ->assertFailed();

    expect($user->refresh()->password)->toBe($oldHash);
});

test('fails when the password is too short', function () {
    $user = User::factory()->create();
    $oldHash = $user->password;

    $this->artisan('users:set-password', ['email' => $user->email])
        ->expectsQuestion('New password', 'short')
        ->expectsQuestion('Confirm the new password', 'short')
        ->assertFailed();

    expect($user->refresh()->password)->toBe($oldHash);
});

test('generates a strong password that lets the account log in', function () {
    $user = User::factory()->create();

    $exitCode = Artisan::call('users:set-password', ['email' => $user->email, '--generate' => true]);
    $shown = generatedPasswordFrom(Artisan::output());

    expect($exitCode)->toBe(0);
    expect($shown)->toHaveLength(24);
    expect(Hash::check($shown, $user->refresh()->password))->toBeTrue();
});

test('shows the generated password once and never asks to type one', function () {
    $user = User::factory()->create();

    $this->artisan('users:set-password', ['email' => $user->email, '--generate' => true])
        ->expectsOutputToContain('Password (shown once)')
        ->expectsOutputToContain('Ajustes > Seguridad')
        ->assertSuccessful();
});

test('generates a different password every time', function () {
    $user = User::factory()->create();

    Artisan::call('users:set-password', ['email' => $user->email, '--generate' => true]);
    $first = generatedPasswordFrom(Artisan::output());
    Artisan::call('users:set-password', ['email' => $user->email, '--generate' => true]);
    $second = generatedPasswordFrom(Artisan::output());

    expect($second)->not->toBe($first);
});

test('lets the account log in with the generated password', function () {
    $user = User::factory()->create();
    Artisan::call('users:set-password', ['email' => $user->email, '--generate' => true]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => generatedPasswordFrom(Artisan::output()),
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

test('fails when no account uses the email', function () {
    $this->artisan('users:set-password', ['email' => 'nobody@example.com'])
        ->expectsOutputToContain('No account uses that email.')
        ->assertFailed();
});
