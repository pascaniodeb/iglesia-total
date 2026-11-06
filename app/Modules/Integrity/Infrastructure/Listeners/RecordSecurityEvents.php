<?php

namespace App\Modules\Integrity\Infrastructure\Listeners;

use App\Models\User;
use App\Modules\Integrity\Application\ActivityLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;

/**
 * Sign-in, sign-out and credential events go to the activity log as security facts.
 *
 * A failed attempt records the account only when the email belongs to one, and never the
 * email typed nor the password: the log must not become a list of guessed credentials.
 */
final readonly class RecordSecurityEvents
{
    public function __construct(private ActivityLog $activityLog) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, function (Login $event): void {
            $this->activityLog->security('auth.login', __('Signed in.'), $this->user($event->user));
        });

        $events->listen(Logout::class, function (Logout $event): void {
            $this->activityLog->security('auth.logout', __('Signed out.'), $this->user($event->user));
        });

        $events->listen(Failed::class, function (Failed $event): void {
            $this->activityLog->security('auth.failed', __('Failed sign-in attempt.'), $this->user($event->user));
        });

        $events->listen(PasswordReset::class, function (PasswordReset $event): void {
            $this->activityLog->security('auth.password_reset', __('Reset the password with an emailed link.'), $this->user($event->user));
        });

        $events->listen(TwoFactorAuthenticationConfirmed::class, function (TwoFactorAuthenticationConfirmed $event): void {
            $this->activityLog->security('auth.two_factor_enabled', __('Enabled two-factor authentication.'), $this->user($event->user));
        });

        $events->listen(TwoFactorAuthenticationDisabled::class, function (TwoFactorAuthenticationDisabled $event): void {
            $this->activityLog->security('auth.two_factor_disabled', __('Disabled two-factor authentication.'), $this->user($event->user));
        });

        $events->listen(RecoveryCodesGenerated::class, function (RecoveryCodesGenerated $event): void {
            $this->activityLog->security('auth.recovery_codes_generated', __('Generated new recovery codes.'), $this->user($event->user));
        });
    }

    private function user(mixed $user): ?User
    {
        return $user instanceof User ? $user : null;
    }
}
