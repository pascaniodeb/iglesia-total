<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
    }

    /**
     * Every permission becomes a Gate ability named after it (`can:members.view`,
     * `$user->can('members.view')`, `@can`...). The pastor is the super administrator;
     * a deactivated account is denied everything, whatever its role.
     */
    protected function configureAuthorization(): void
    {
        Gate::before(function (User $user): ?bool {
            if (! $user->is_active) {
                return false;
            }

            return $user->role === UserRole::Pastor ? true : null;
        });

        foreach (Permission::cases() as $permission) {
            Gate::define(
                $permission->value,
                fn (User $user): bool => $user->role->grants($permission),
            );
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
