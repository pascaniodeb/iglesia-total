<?php

namespace App\Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Members\Application\MemberDirectory;
use App\Modules\Members\Domain\MemberRef;
use App\Modules\Shared\Tables\TableQuery;
use App\Modules\Shared\Time\ChurchCalendar;
use App\Modules\Treasury\Application\Actions\SaveTithe;
use App\Modules\Treasury\Application\Actions\TrashTithe;
use App\Modules\Treasury\Http\Requests\TitheRequest;
use App\Modules\Treasury\Http\Tables\TitheListing;
use App\Modules\Treasury\Infrastructure\Models\Tithe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tithes book (tithes.view to read, tithes.manage to record, correct, trash and restore):
 * only the pastor holds them. A tithe is not income of the church and never reaches the books.
 */
class TitheController extends Controller
{
    public function index(Request $request, MemberDirectory $members): Response
    {
        $table = TableQuery::fromRequest(
            $request,
            TitheListing::sortable(),
            TitheListing::DEFAULT_SORT,
            TitheListing::facets(),
            defaultDirection: 'desc',
            defaultStatus: 'active',
        );

        $searchable = TitheListing::searchable($members->idsMatching($table->search));
        $totals = TitheListing::totals($table->filter(TitheListing::base($table), $searchable));

        $page = $table->paginate(TitheListing::base($table), $searchable);
        $people = $members->findMany(array_values(array_map(fn (Tithe $tithe): string => $tithe->member_id, $page->items())));

        return Inertia::render('treasury/tithes/index', [
            'tithes' => $page->through(fn (Tithe $tithe) => TitheListing::row($tithe, $people)),
            'filters' => $table->filters(),
            'totals' => $totals,
            'months' => array_map(strval(...), Tithe::query()->select('month_key')->distinct()->orderByDesc('month_key')->limit(36)->pluck('month_key')->all()),
            'today' => ChurchCalendar::today()->format('Y-m-d'),
        ]);
    }

    public function store(TitheRequest $request, SaveTithe $save): RedirectResponse
    {
        /** @var array{tithe_date: string, member_id: string, notes?: string|null, amount_ves?: string|null, amount_usd?: string|null, amount_cop?: string|null} $data */
        $data = $request->validated();
        $save(null, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tithe recorded.')]);

        return back();
    }

    public function update(TitheRequest $request, Tithe $tithe, SaveTithe $save): RedirectResponse
    {
        /** @var array{tithe_date: string, member_id: string, notes?: string|null, amount_ves?: string|null, amount_usd?: string|null, amount_cop?: string|null} $data */
        $data = $request->validated();
        $save($tithe, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tithe corrected.')]);

        return back();
    }

    public function destroy(Tithe $tithe, TrashTithe $trash): RedirectResponse
    {
        $trash->trash($tithe);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tithe sent to the trash.')]);

        return back();
    }

    public function restore(Tithe $tithe, TrashTithe $trash): RedirectResponse
    {
        $trash->restore($tithe);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tithe restored.')]);

        return back();
    }

    /**
     * Members of the registry that match the search, for the tithe form.
     */
    public function memberOptions(Request $request, MemberDirectory $members): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string', 'max:100']]);

        return response()->json([
            'data' => array_map(fn (MemberRef $member) => $member->toOption(), $members->search((string) $request->query('search', ''))),
        ]);
    }
}
