<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * The staff home. Accounts without access to the dashboard (regular members) land on
     * their own profile instead of an error page, which is also where login sends them.
     */
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if ($request->user()?->cannot(Permission::DashboardView->value)) {
            return redirect()->route('profile.edit');
        }

        return Inertia::render('dashboard');
    }
}
