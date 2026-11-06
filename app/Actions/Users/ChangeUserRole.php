<?php

namespace App\Actions\Users;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\User;
use App\Modules\Integrity\Application\ActivityLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The only way to change the role of an account. Called by the users screen (with an
 * actor) and by the `users:set-role` command (trusted operator, no actor). The change and its
 * security record in the activity log are written together.
 */
class ChangeUserRole
{
    public function __construct(private readonly ActivityLog $activityLog) {}

    /**
     * @throws AuthorizationException when the actor is not allowed to manage users
     * @throws ValidationException when the change breaks a safeguard
     */
    public function __invoke(User $user, UserRole $role, ?User $actor = null): User
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize(Permission::UsersManage->value);

            // Whoever holds the switch must not be able to lock themselves out.
            if ($actor->is($user)) {
                throw ValidationException::withMessages([
                    'role' => __('You cannot change your own role.'),
                ]);
            }
        }

        // A member account is the portal of a person of the registry, so it needs one.
        if ($role === UserRole::Member && $user->member_id === null) {
            throw ValidationException::withMessages([
                'role' => __('A member account must be linked to a member of the registry.'),
            ]);
        }

        if ($this->wouldLeaveNoActivePastor($user, $role)) {
            throw ValidationException::withMessages([
                'role' => __('There must be at least one active pastor.'),
            ]);
        }

        $previous = $user->role;

        if ($previous === $role) {
            return $user;
        }

        DB::transaction(function () use ($user, $role, $previous): void {
            $user->forceFill(['role' => $role])->save();

            $this->activityLog->security(
                'user.role_changed',
                __('Changed the role of :name from :from to :to.', [
                    'name' => $user->name,
                    'from' => $previous->label(),
                    'to' => $role->label(),
                ]),
                $user,
                ['role' => ['from' => $previous->value, 'to' => $role->value]],
            );
        });

        return $user;
    }

    private function wouldLeaveNoActivePastor(User $user, UserRole $newRole): bool
    {
        if ($user->role !== UserRole::Pastor || $newRole === UserRole::Pastor) {
            return false;
        }

        return ! User::query()
            ->where('role', UserRole::Pastor->value)
            ->where('is_active', true)
            ->whereKeyNot($user->getKey())
            ->exists();
    }
}
