<?php

use App\Http\Controllers\DashboardController;
use App\Modules\Accounting\Http\Controllers\AccountingAccountController;
use App\Modules\Integrity\Http\Controllers\ActivityLogController;
use App\Modules\Members\Http\Controllers\MemberController;
use App\Modules\Treasury\Http\Controllers\OfferingCategoryController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // Members registry. Fixed paths go before {member}.
    Route::middleware('can:members.view')->prefix('members')->name('members.')->group(function () {
        Route::get('/', [MemberController::class, 'index'])->name('index');

        Route::middleware('can:members.manage')->group(function () {
            Route::get('create', [MemberController::class, 'create'])->name('create');
            Route::post('/', [MemberController::class, 'store'])->name('store');
            Route::get('spouse-options', [MemberController::class, 'spouseOptions'])->name('spouse-options');
        });

        // A member in the trash can still be opened (read-only) and restored.
        Route::get('{member}', [MemberController::class, 'show'])->whereUuid('member')->withTrashed()->name('show');

        Route::middleware('can:members.manage')->group(function () {
            Route::put('{member}', [MemberController::class, 'update'])->whereUuid('member')->name('update');
            Route::delete('{member}', [MemberController::class, 'destroy'])->whereUuid('member')->name('destroy');
            Route::post('{member}/restore', [MemberController::class, 'restore'])->whereUuid('member')->withTrashed()->name('restore');
        });
    });

    // Chart of accounts (Accounting). Fixed paths go before {account}.
    Route::middleware('can:catalogs.view')->prefix('accounting/accounts')->name('accounting.accounts.')->group(function () {
        Route::get('/', [AccountingAccountController::class, 'index'])->name('index');

        Route::middleware('can:catalogs.manage')->group(function () {
            Route::post('/', [AccountingAccountController::class, 'store'])->name('store');
            Route::put('{account}', [AccountingAccountController::class, 'update'])->whereUuid('account')->name('update');
            Route::patch('{account}/active', [AccountingAccountController::class, 'toggle'])->whereUuid('account')->name('toggle');
        });
    });

    // Offering categories (Treasury).
    Route::middleware('can:catalogs.view')->prefix('treasury/offering-categories')->name('treasury.offering-categories.')->group(function () {
        Route::get('/', [OfferingCategoryController::class, 'index'])->name('index');

        Route::middleware('can:catalogs.manage')->group(function () {
            Route::post('/', [OfferingCategoryController::class, 'store'])->name('store');
            Route::put('{category}', [OfferingCategoryController::class, 'update'])->whereUuid('category')->name('update');
            Route::patch('{category}/active', [OfferingCategoryController::class, 'toggle'])->whereUuid('category')->name('toggle');
        });
    });

    // Activity log (Integrity): read-only.
    Route::get('activity-log', [ActivityLogController::class, 'index'])
        ->middleware('can:activity_log.view')
        ->name('activity-log.index');
});

require __DIR__.'/settings.php';
