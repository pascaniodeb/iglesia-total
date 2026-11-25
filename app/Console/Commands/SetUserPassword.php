<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Integrity\Application\ActivityLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Console\Output\OutputInterface;

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
        // Strong by construction, so it skips the rules meant for typed passwords (which may
        // call an external service).
        $password = $this->newPassword();

        $this->store($user, $password);

        $this->components->info("Password updated for {$user->email}.");
        $this->line('Password (shown once)');
        // Raw, on its own line: the console formatter reads "\<", "<tag>" and a trailing "\" as
        // formatting, and would show a password different from the one that was stored.
        $this->output->writeln($password, OutputInterface::OUTPUT_RAW);
        $this->components->warn('Change it after signing in: Ajustes > Seguridad.');

        return self::SUCCESS;
    }

    /**
     * 24 random characters with letters, numbers and symbols.
     */
    protected function newPassword(): string
    {
        return Str::password(24);
    }

    /**
     * The "hashed" cast hashes the value. Rotating the remember token ends any
     * "remember me" session opened with the previous password.
     */
    /**
     * Saves the password and records that the operator set it (never the password itself).
     */
    private function store(User $user, string $password): void
    {
        DB::transaction(function () use ($user, $password): void {
            $user->forceFill([
                'password' => $password,
                'remember_token' => null,
            ])->save();

            $this->laravel->make(ActivityLog::class)->security(
                'user.password_set',
                __('Set the password of :name from the server.', ['name' => $user->name]),
                $user,
            );
        });
    }
}
