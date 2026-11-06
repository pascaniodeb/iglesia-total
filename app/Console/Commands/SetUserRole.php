<?php

namespace App\Console\Commands;

use App\Actions\Users\ChangeUserRole;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Sets the role of an account from the server, for the operator. The same safeguards as
 * the application apply (at least one active pastor, a member account needs its member).
 */
#[Signature('users:set-role {email : Email of the account} {role : pastor, asistente, secretario, tesorero, contralor or miembro}')]
#[Description('Set the role of a user account')]
class SetUserRole extends Command
{
    public function handle(ChangeUserRole $changeUserRole): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->components->error('No account uses that email.');

            return self::FAILURE;
        }

        $role = UserRole::tryFrom((string) $this->argument('role'));

        if ($role === null) {
            $valid = implode(', ', array_column(UserRole::cases(), 'value'));
            $this->components->error("Unknown role. Valid roles: {$valid}.");

            return self::FAILURE;
        }

        try {
            $changeUserRole($user, $role);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->components->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->components->info("{$user->email} is now {$role->label()}.");

        return self::SUCCESS;
    }
}
