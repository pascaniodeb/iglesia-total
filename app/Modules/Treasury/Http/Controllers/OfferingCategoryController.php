<?php

namespace App\Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Domain\AccountRef;
use App\Modules\Shared\Tables\TableQuery;
use App\Modules\Treasury\Application\Actions\SaveOfferingCategory;
use App\Modules\Treasury\Application\Actions\SetOfferingCategoryActive;
use App\Modules\Treasury\Http\Requests\OfferingCategoryRequest;
use App\Modules\Treasury\Http\Tables\OfferingCategoryListing;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Offering categories (catalogs.view to read, catalogs.manage to change).
 */
class OfferingCategoryController extends Controller
{
    public function index(Request $request, ChartOfAccounts $chart): Response
    {
        $table = TableQuery::fromRequest(
            $request,
            OfferingCategoryListing::sortable(),
            OfferingCategoryListing::DEFAULT_SORT,
            defaultStatus: 'active',
        );

        $page = $table->paginate(OfferingCategoryListing::base(), OfferingCategoryListing::searchable());
        $accounts = $chart->findMany(array_values(array_map(
            fn (OfferingCategory $category): string => $category->account_id,
            $page->items(),
        )));

        return Inertia::render('treasury/offering-categories/index', [
            'categories' => $page->through(fn (OfferingCategory $category) => OfferingCategoryListing::row($category, $accounts)),
            'filters' => $table->filters(),
            'accounts' => array_map(fn (AccountRef $account) => $account->toOption(), $chart->activeIncomeAccounts()),
        ]);
    }

    public function store(OfferingCategoryRequest $request, SaveOfferingCategory $save): RedirectResponse
    {
        /** @var array{name: string, account_id: string} $data */
        $data = $request->validated();
        $category = $save(null, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category :name created.', ['name' => $category->name])]);

        return back();
    }

    public function update(OfferingCategoryRequest $request, OfferingCategory $category, SaveOfferingCategory $save): RedirectResponse
    {
        /** @var array{name: string, account_id: string} $data */
        $data = $request->validated();
        $save($category, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category :name updated.', ['name' => $category->name])]);

        return back();
    }

    public function toggle(Request $request, OfferingCategory $category, SetOfferingCategoryActive $setActive): RedirectResponse
    {
        $request->validate(['is_active' => ['required', 'boolean']]);
        $setActive($category, $request->boolean('is_active'));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $category->is_active
                ? __('Category :name reactivated.', ['name' => $category->name])
                : __('Category :name deactivated.', ['name' => $category->name]),
        ]);

        return back();
    }
}
