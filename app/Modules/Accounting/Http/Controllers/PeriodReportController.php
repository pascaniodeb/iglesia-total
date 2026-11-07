<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Application\Queries\PeriodReport;
use App\Modules\Accounting\Http\Concerns\ReadsReportPeriod;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The accounting report of a month, quarter, semester, year or range of months (accounting.view):
 * one cash statement for the whole span and the net of each income and expense code.
 */
class PeriodReportController extends Controller
{
    use ReadsReportPeriod;

    public function index(Request $request): Response
    {
        $period = $this->requestedPeriod($request);

        return Inertia::render('accounting/reports/index', [
            'report' => PeriodReport::of($period),
            'options' => $this->periodOptions($period),
        ]);
    }
}
