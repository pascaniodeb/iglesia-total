<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Sets the password of an account. A typed password is read from a hidden prompt, never
 * from an argument, so it does not end up in the shell history or the process list.
 *
 * With --generate no typing is needed: a strong random password is created and shown once.
 */
#[Signature('users:set-password {email : Email of the account} {--generate : Create a strong random password and show it once}')]
#[Description('Set the password of a user account')]
class SetUserPassword extends Command
{
    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->components->error('No account uses that email.');

            return self::FAILURE;
        }

        if ($this->option('generate')) {
            return $this->setGeneratedPassword($user);
        }

        $input = [
            'password' => $this->secret('New password'),
            'password_confirmation' => $this->secret('Confirm the new password'),
        ];

        $validator = Validator::make($input, [
            'password' => ['required', 'confirmed', Password::default()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $this->store($user, $input['password']);

        $this->components->info("Password updated for {$user->email}.");

        return self::SUCCESS;
    }

    private function setGeneratedPassword(User $user): int
    {
        // 24 random characters with letters, numbers and symbols: strong by construction,
        // so it skips the rules meant for typed passwords (which may call an external service).
        $password = Str::password(24);

        $this->store($user, $password);

        $this->components->info("Password updated for {$user->email}.");
        $this->components->twoColumnDetail('Password (shown once)', $password);
        $this->components->warn('Change it after signing in: Ajustes > Seguridad.');

        return self::SUCCESS;
    }

    /**
     * The "hashed" cast hashes the value. Rotating the remember token ends any
     * "remember me" session opened with the previous password.
     */
    private function store(User $user, string $password): void
    {
        $user->forceFill([
            'password' => $password,
            'remember_token' => null,
        ])->save();
    }
}
