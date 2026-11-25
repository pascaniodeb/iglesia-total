<?php

use App\Console\Commands\SetUserPassword;
use App\Models\User;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

/**
 * The password the command printed ("Password (shown once) ..... <password>").
 */
function generatedPasswordFrom(string $output): string
{
    // The password is alone on the line after its label: nothing around it to trim, so a password
    // that starts with a dot or ends with a backslash comes back whole.
    preg_match('/Password \(shown once\)\R(\S+)/', $output, $matches);

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

test('shows the generated password intact when it has characters the console reads as formatting', function (string $password) {
    // Attributes are not inherited: the subclass declares the command's name again.
    Artisan::registerCommand(new #[Signature('users:set-password {email} {--generate}')] class($password) extends SetUserPassword
    {
        public function __construct(private readonly string $fixed)
        {
            parent::__construct();
        }

        protected function newPassword(): string
        {
            return $this->fixed;
        }
    });
    $user = User::factory()->create();

    Artisan::call('users:set-password', ['email' => $user->email, '--generate' => true]);

    expect(generatedPasswordFrom(Artisan::output()))->toBe($password)
        ->and(Hash::check($password, $user->refresh()->password))->toBeTrue();
})->with([
    'a backslash before an angle bracket' => ['aB3x\\<Y7qLmN4pRt9sKd2Wz'],
    'something that looks like a closing tag' => ['aB3x</>Y7qLmN4pRt9sKd2Wz'],
    'something that looks like a style tag' => ['aB3x<fg=red>Y7qLmN4pRt9sKd2Wz'],
    'a trailing backslash' => ['aB3xY7qLmN4pRt9sKd2Wz\\'],
    'brackets and dots' => ['.aB3x[Y7]qLmN4pRt9sKd2Wz.'],
]);
