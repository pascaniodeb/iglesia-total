<?php

namespace App\Modules\Integrity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Integrity\Domain\Enums\ActivityCategory;
use App\Modules\Integrity\Http\Tables\ActivityLogListing;
use App\Modules\Shared\Tables\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The activity log screen (activity_log.view, enforced by the route). Read-only.
 */
class ActivityLogController extends Controller
{
    public function index(Request $request): Response
    {
        $table = TableQuery::fromRequest(
            $request,
            ActivityLogListing::sortable(),
            ActivityLogListing::DEFAULT_SORT,
            ActivityLogListing::facets(),
            defaultDirection: 'desc',
            withStatus: false,
        );

        $entries = $table
            ->paginate(ActivityLogListing::base($table), ActivityLogListing::searchable())
            ->through(ActivityLogListing::row(...));

        return Inertia::render('activity-log/index', [
            'entries' => $entries,
            'filters' => $table->filters(),
            'categories' => ActivityCategory::options(),
        ]);
    }
}
