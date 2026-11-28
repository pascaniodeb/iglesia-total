<?php

namespace App\Modules\Integrity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Integrity\Http\Tables\LegacyActivityListing;
use App\Modules\Shared\Tables\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The archive of the old system's activity log (activity_log.view, enforced by the route).
 * Read-only: what was done before the cut-over, as it was recorded.
 */
class LegacyActivityLogController extends Controller
{
    public function index(Request $request): Response
    {
        $table = TableQuery::fromRequest(
            $request,
            LegacyActivityListing::sortable(),
            LegacyActivityListing::DEFAULT_SORT,
            [],
            defaultDirection: 'desc',
            withStatus: false,
        );

        return Inertia::render('activity-log/legacy', [
            'entries' => $table
                ->paginate(LegacyActivityListing::base($table), LegacyActivityListing::searchable())
                ->through(LegacyActivityListing::row(...)),
            'filters' => $table->filters(),
        ]);
    }
}
