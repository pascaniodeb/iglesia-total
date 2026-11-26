<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Application\Queries\PeriodReport;
use App\Modules\Accounting\Http\Concerns\ReadsReportPeriod;
use App\Modules\Accounting\Infrastructure\Pdf\PeriodReportPdf;
use App\Modules\Church\Application\ChurchIdentity;
use App\Modules\Integrity\Application\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
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

    /**
     * The report as a printed document, drawn again on every request from the same figures as the
     * screen (a draft while a month of the period is open).
     */
    public function pdf(Request $request, ChurchIdentity $church, PeriodReportPdf $document, ActivityLog $activityLog): HttpResponse
    {
        if ($church->profile() === null) {
            abort(409, __('Fill in the church settings first.'));
        }

        $period = $this->requestedPeriod($request);
        $bytes = $document->render(PeriodReport::of($period));

        $activityLog->access('accounting_report.pdf_downloaded', __('Opened the PDF of the accounting report of :period.', ['period' => $period->key()]));

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="reporte-contabilidad-'.$period->key().'.pdf"',
            'Cache-Control' => 'private, no-cache, must-revalidate',
        ]);
    }
}
