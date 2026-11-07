<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Application\Queries\GeneralLedger;
use App\Modules\Accounting\Application\Queries\PeriodReport;
use App\Modules\Accounting\Domain\Enums\SystemFunction;
use App\Modules\Accounting\Http\Concerns\ReadsReportPeriod;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The general ledger (Libro Mayor, accounting.view): one account over a period, with its balance
 * before, every entry that moved it with the running balance, and its balance after. CAJA by
 * default, since it is the account people reconcile against the cash they count.
 */
class GeneralLedgerController extends Controller
{
    use ReadsReportPeriod;

    public function index(Request $request): Response
    {
        $period = $this->requestedPeriod($request);
        $code = $request->query('account');
        $account = (is_string($code) ? AccountingAccount::query()->where('code', $code)->first() : null)
            ?? AccountingAccount::system(SystemFunction::Cash);
        $report = PeriodReport::of($period);

        return Inertia::render('accounting/ledger/index', [
            'ledger' => GeneralLedger::of($account, $period),
            'period' => [
                'granularity' => $report['granularity'],
                'period' => $report['period'],
                'label' => $report['label'],
                'months' => $report['months'],
                'is_draft' => $report['is_draft'],
            ],
            'accounts' => array_values(AccountingAccount::query()
                ->orderedByCode()
                ->get(['code', 'name', 'type'])
                ->map(fn (AccountingAccount $option) => ['code' => $option->code, 'name' => $option->name, 'type' => $option->type->value])
                ->all()),
            'options' => $this->periodOptions($period),
        ]);
    }
}
