<?php

namespace App\Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Members\Application\MemberDirectory;
use App\Modules\Members\Domain\MemberRef;
use App\Modules\Shared\Tables\TableQuery;
use App\Modules\Shared\Time\ChurchCalendar;
use App\Modules\Treasury\Application\Actions\SaveOffering;
use App\Modules\Treasury\Application\Actions\TrashOffering;
use App\Modules\Treasury\Http\Requests\OfferingRequest;
use App\Modules\Treasury\Http\Tables\OfferingListing;
use App\Modules\Treasury\Infrastructure\Models\Offering;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use App\Modules\Treasury\Infrastructure\Models\WeeklyClosure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The offerings registry (offerings.view to read, offerings.manage to record and correct).
 */
class OfferingController extends Controller
{
    public function index(Request $request, MemberDirectory $members): Response
    {
        $table = TableQuery::fromRequest(
            $request,
            OfferingListing::sortable(),
            OfferingListing::DEFAULT_SORT,
            OfferingListing::facets(),
            defaultDirection: 'desc',
            defaultStatus: 'active',
        );

        $searchable = OfferingListing::searchable($members->idsMatching($table->search));
        $totals = OfferingListing::totals($table->filter(OfferingListing::base($table), $searchable));

        $page = $table->paginate(OfferingListing::base($table), $searchable, fn ($query) => $query->with('category'));
        $people = $members->findMany(array_values(array_filter(array_map(
            fn (Offering $offering): ?string => $offering->member_id,
            $page->items(),
        ))));

        $closedWeeks = array_flip(array_map(strval(...), WeeklyClosure::query()
            ->whereIn('week_key', array_map(fn (Offering $offering): string => $offering->week_key, $page->items()))
            ->pluck('week_key')
            ->all()));

        return Inertia::render('treasury/offerings/index', [
            'offerings' => $page->through(fn (Offering $offering) => OfferingListing::row($offering, $people, $closedWeeks)),
            'filters' => $table->filters(),
            'totals' => $totals,
            'categories' => OfferingCategory::query()->orderBy('name')->get()->map(fn (OfferingCategory $category) => [
                'value' => $category->id,
                'label' => $category->name,
                'is_active' => $category->is_active,
            ]),
            'months' => Offering::query()->select('month_key')->distinct()->orderByDesc('month_key')->limit(36)->pluck('month_key'),
            'today' => ChurchCalendar::today()->format('Y-m-d'),
        ]);
    }

    public function store(OfferingRequest $request, SaveOffering $save): RedirectResponse
    {
        /** @var array{offering_date: string, offering_category_id: string, member_id?: string|null, concept?: string|null, amount_ves?: string|null, amount_usd?: string|null, amount_cop?: string|null} $data */
        $data = $request->validated();
        $save(null, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Offering recorded.')]);

        return back();
    }

    public function update(OfferingRequest $request, Offering $offering, SaveOffering $save): RedirectResponse
    {
        /** @var array{offering_date: string, offering_category_id: string, member_id?: string|null, concept?: string|null, amount_ves?: string|null, amount_usd?: string|null, amount_cop?: string|null} $data */
        $data = $request->validated();
        $save($offering, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Offering corrected.')]);

        return back();
    }

    public function destroy(Offering $offering, TrashOffering $trash): RedirectResponse
    {
        try {
            $trash($offering);
        } catch (ValidationException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => (string) collect($exception->errors())->flatten()->first()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Offering sent to the trash.')]);

        return back();
    }

    /**
     * Members of the registry that match the search, for the offering form.
     */
    public function memberOptions(Request $request, MemberDirectory $members): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string', 'max:100']]);

        return response()->json([
            'data' => array_map(fn (MemberRef $member) => $member->toOption(), $members->search((string) $request->query('search', ''))),
        ]);
    }
}
