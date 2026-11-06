<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * A deactivated account cannot use the application, however it authenticated
 * (password, passkey or a session opened before the deactivation).
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->is_active) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Flashed after the invalidation so it survives into the new session.
        Inertia::flash('toast', [
            'type' => 'error',
            'message' => __('Your account is deactivated. Contact the church secretary.'),
        ]);

        return redirect()->route('login');
    }
}
