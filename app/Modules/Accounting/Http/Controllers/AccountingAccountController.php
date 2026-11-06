<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Application\Actions\SaveAccountingAccount;
use App\Modules\Accounting\Application\Actions\SetAccountingAccountActive;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Http\Requests\AccountingAccountRequest;
use App\Modules\Accounting\Http\Tables\AccountingAccountListing;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Shared\Tables\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The chart of accounts (catalogs.view to read, catalogs.manage to change; enforced by the
 * routes and the form request).
 */
class AccountingAccountController extends Controller
{
    public function index(Request $request): Response
    {
        $table = TableQuery::fromRequest(
            $request,
            AccountingAccountListing::sortable(),
            AccountingAccountListing::DEFAULT_SORT,
            AccountingAccountListing::facets(),
            defaultStatus: 'active',
        );

        $accounts = $table
            ->paginate(AccountingAccountListing::base($table), AccountingAccountListing::searchable())
            ->through(AccountingAccountListing::row(...));

        return Inertia::render('accounting/accounts/index', [
            'accounts' => $accounts,
            'filters' => $table->filters(),
            'types' => AccountType::options(),
        ]);
    }

    public function store(AccountingAccountRequest $request, SaveAccountingAccount $save): RedirectResponse
    {
        /** @var array{code: string, name: string} $data */
        $data = $request->validated();
        $account = $save(null, $data);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Account :code created.', ['code' => $account->code]),
        ]);

        return back();
    }

    public function update(AccountingAccountRequest $request, AccountingAccount $account, SaveAccountingAccount $save): RedirectResponse
    {
        /** @var array{name: string} $data */
        $data = $request->validated();
        $save($account, $data);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Account :code updated.', ['code' => $account->code]),
        ]);

        return back();
    }

    public function toggle(Request $request, AccountingAccount $account, SetAccountingAccountActive $setActive): RedirectResponse
    {
        $request->validate(['is_active' => ['required', 'boolean']]);

        try {
            $setActive($account, $request->boolean('is_active'));
        } catch (ValidationException $exception) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => (string) collect($exception->errors())->flatten()->first(),
            ]);

            return back();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $account->is_active
                ? __('Account :code reactivated.', ['code' => $account->code])
                : __('Account :code deactivated.', ['code' => $account->code]),
        ]);

        return back();
    }
}
