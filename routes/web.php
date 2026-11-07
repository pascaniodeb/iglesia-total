<?php

use App\Http\Controllers\DashboardController;
use App\Modules\Accounting\Http\Controllers\AccountingAccountController;
use App\Modules\Accounting\Http\Controllers\AccountingPeriodController;
use App\Modules\Accounting\Http\Controllers\JournalController;
use App\Modules\Integrity\Http\Controllers\ActivityLogController;
use App\Modules\Members\Http\Controllers\MemberController;
use App\Modules\Treasury\Http\Controllers\ExchangeRateController;
use App\Modules\Treasury\Http\Controllers\MonthlyOfferingClosureController;
use App\Modules\Treasury\Http\Controllers\OfferingCategoryController;
use App\Modules\Treasury\Http\Controllers\OfferingController;
use App\Modules\Treasury\Http\Controllers\OfferingImportController;
use App\Modules\Treasury\Http\Controllers\WeeklyClosureController;
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

    // Journal (Accounting): step 3 of the month circuit. Fixed paths go before {entry}.
    Route::middleware('can:accounting.view')->prefix('accounting/journal')->name('accounting.journal.')->group(function () {
        Route::get('/', [JournalController::class, 'index'])->name('index');

        Route::middleware(['can:accounting.manage', 'throttle:30,1'])->group(function () {
            Route::post('/', [JournalController::class, 'store'])->name('store');
            Route::post('exchange', [JournalController::class, 'exchange'])->name('exchange');
            Route::post('{entry}/cancel', [JournalController::class, 'cancel'])->whereUuid('entry')->name('cancel');
            Route::put('{entry}', [JournalController::class, 'correct'])->whereUuid('entry')->name('correct');
        });
    });

    // Accounting close (Accounting): step 4 of the month circuit. A closed month never reopens.
    Route::middleware('can:accounting.view')->prefix('accounting/periods')->name('accounting.periods.')->group(function () {
        Route::get('/', [AccountingPeriodController::class, 'index'])->name('index');

        Route::middleware(['can:accounting.manage', 'throttle:10,1'])->group(function () {
            Route::post('/', [AccountingPeriodController::class, 'store'])->name('store');
            Route::post('seal', [AccountingPeriodController::class, 'seal'])->name('seal');
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

    // Offerings registry (Treasury). Fixed paths go before {offering}.
    Route::middleware('can:offerings.view')->prefix('treasury/offerings')->name('treasury.offerings.')->group(function () {
        Route::get('/', [OfferingController::class, 'index'])->name('index');

        Route::middleware('can:offerings.manage')->group(function () {
            Route::post('/', [OfferingController::class, 'store'])->name('store');
            Route::get('member-options', [OfferingController::class, 'memberOptions'])->name('member-options');
            Route::get('import/template', [OfferingImportController::class, 'template'])->name('import.template');
            Route::post('import/preview', [OfferingImportController::class, 'preview'])->middleware('throttle:20,1')->name('import.preview');
            Route::post('import', [OfferingImportController::class, 'store'])->middleware('throttle:10,1')->name('import.store');
            Route::put('{offering}', [OfferingController::class, 'update'])->whereUuid('offering')->name('update');
            Route::delete('{offering}', [OfferingController::class, 'destroy'])->whereUuid('offering')->name('destroy');
        });
    });

    // Weekly closures (Treasury): step 1 of the month circuit. A closed week never reopens.
    Route::middleware('can:closures.view')->prefix('treasury/weekly-closures')->name('treasury.weekly-closures.')->group(function () {
        Route::get('/', [WeeklyClosureController::class, 'index'])->name('index');

        Route::middleware('can:closures.manage')->group(function () {
            Route::post('/', [WeeklyClosureController::class, 'store'])->middleware('throttle:10,1')->name('store');
            Route::post('seal', [WeeklyClosureController::class, 'seal'])->middleware('throttle:10,1')->name('seal');
        });
    });

    // Monthly offering closures (Treasury): step 2 of the month circuit, posts to the journal.
    Route::middleware('can:closures.view')->prefix('treasury/monthly-offering-closures')->name('treasury.monthly-offering-closures.')->group(function () {
        Route::get('/', [MonthlyOfferingClosureController::class, 'index'])->name('index');
        Route::post('/', [MonthlyOfferingClosureController::class, 'store'])->middleware(['can:closures.manage', 'throttle:10,1'])->name('store');
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

    // Exchange rates (Treasury): they inform and compare, never feed the books.
    Route::middleware('can:catalogs.view')->prefix('treasury/exchange-rates')->name('treasury.exchange-rates.')->group(function () {
        Route::get('/', [ExchangeRateController::class, 'index'])->name('index');

        Route::middleware('can:catalogs.manage')->group(function () {
            Route::post('/', [ExchangeRateController::class, 'store'])->name('store');
            Route::post('sync', [ExchangeRateController::class, 'sync'])->middleware('throttle:6,1')->name('sync');
        });
    });

    // Activity log (Integrity): read-only.
    Route::get('activity-log', [ActivityLogController::class, 'index'])
        ->middleware('can:activity_log.view')
        ->name('activity-log.index');
});

require __DIR__.'/settings.php';
