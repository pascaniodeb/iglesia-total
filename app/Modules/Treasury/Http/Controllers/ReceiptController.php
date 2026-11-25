<?php

namespace App\Modules\Treasury\Http\Controllers;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Accounting\Application\CashMovements;
use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Shared\Tables\TableQuery;
use App\Modules\Shared\Time\ChurchCalendar;
use App\Modules\Treasury\Application\Actions\IssueReceipt;
use App\Modules\Treasury\Application\Actions\SaveReceiptSettings;
use App\Modules\Treasury\Application\Actions\VoidReceipt;
use App\Modules\Treasury\Application\Receipts\ReceiptBook;
use App\Modules\Treasury\Domain\Receipts\PaymentMethod;
use App\Modules\Treasury\Domain\Receipts\ReceiptNumber;
use App\Modules\Treasury\Http\Requests\ReceiptRequest;
use App\Modules\Treasury\Http\Tables\ReceiptListing;
use App\Modules\Treasury\Infrastructure\Models\Receipt;
use App\Modules\Treasury\Infrastructure\Models\ReceiptSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The receipt book (accounting.view to read, accounting.manage to issue; receipts.administer to
 * void a receipt and to set the number the book starts at).
 */
class ReceiptController extends Controller
{
    public function index(Request $request, ChartOfAccounts $chart, CashMovements $movements): Response
    {
        $table = TableQuery::fromRequest(
            $request,
            ReceiptListing::sortable(),
            ReceiptListing::DEFAULT_SORT,
            ReceiptListing::facets(),
            defaultDirection: 'desc',
            withStatus: false,
        );

        $page = $table->paginate(ReceiptListing::base($table), ReceiptListing::searchable(), fn ($query) => $query->with('void'));
        $accounts = $chart->findMany(array_values(array_unique(array_map(fn (Receipt $receipt): string => $receipt->account_id, $page->items()))));
        $today = ChurchCalendar::today()->format('Y-m-d');
        $canIssue = $request->user()?->can(Permission::AccountingManage->value) ?? false;

        return Inertia::render('treasury/receipts/index', [
            'receipts' => $page->through(fn (Receipt $receipt) => ReceiptListing::row($receipt, $accounts)),
            'filters' => $table->filters(),
            'months' => array_map(strval(...), DB::table('receipts')->selectRaw("DISTINCT to_char(issued_on, 'YYYY-MM') AS month")->orderByDesc('month')->limit(36)->pluck('month')->all()),
            'next_number' => ReceiptNumber::format(ReceiptBook::nextNumber()),
            'start_number' => ReceiptSetting::current()->start_number,
            'today' => $today,
            'can_issue' => $canIssue,
            'can_administer' => $request->user()?->can(Permission::ReceiptsAdminister->value) ?? false,
            // What the issue form needs, only for who can issue.
            'form' => $canIssue ? [
                'accounts' => [
                    'income' => array_map(fn ($account) => $account->toOption(), $chart->activeIncomeAccounts()),
                    'expense' => array_map(fn ($account) => $account->toOption(), $chart->activeExpenseAccounts()),
                ],
                'available' => $movements->available($today),
                'window' => $movements->window(),
                'payment_methods' => PaymentMethod::options(),
            ] : null,
        ]);
    }

    public function show(Request $request, Receipt $receipt, ChartOfAccounts $chart): Response
    {
        $receipt->load('void');
        $account = $chart->find($receipt->account_id);
        $people = User::query()
            ->whereKey(array_values(array_filter([$receipt->created_by, $receipt->void?->voided_by])))
            ->pluck('name', 'id');

        return Inertia::render('treasury/receipts/show', [
            'receipt' => [
                'number' => $receipt->number,
                'number_label' => ReceiptNumber::format($receipt->number),
                'type' => $receipt->type->value,
                'type_label' => $receipt->type->label(),
                'issued_on' => $receipt->issued_on->toDateString(),
                'month' => $receipt->issued_on->format('Y-m'),
                'account_code' => $account?->code,
                'account_name' => $account?->name,
                'holder' => $receipt->holder,
                'counterparty' => $receipt->counterparty,
                'amount_ves' => $receipt->amount_ves,
                'amount_usd' => $receipt->amount_usd,
                'amount_cop' => $receipt->amount_cop,
                'concept' => $receipt->concept,
                'payment_method' => $receipt->payment_method->value,
                'payment_method_label' => $receipt->payment_method->label(),
                'bank' => $receipt->bank,
                'payment_reference' => $receipt->payment_reference,
                'payment_date' => $receipt->payment_date?->toDateString(),
                'received_by_name' => $receipt->received_by_name,
                'received_by_id_number' => $receipt->received_by_id_number,
                'delivered_by_name' => $receipt->delivered_by_name,
                'delivered_by_id_number' => $receipt->delivered_by_id_number,
                'issued_by' => $receipt->created_by === null ? null : ($people[$receipt->created_by] ?? null),
                'created_at' => $receipt->created_at->toIso8601String(),
                'void' => $receipt->void === null ? null : [
                    'reason' => $receipt->void->reason,
                    'voided_at' => $receipt->void->voided_at->toIso8601String(),
                    'voided_by' => $receipt->void->voided_by === null ? null : ($people[$receipt->void->voided_by] ?? null),
                ],
            ],
            'can_administer' => $request->user()?->can(Permission::ReceiptsAdminister->value) ?? false,
        ]);
    }

    public function store(ReceiptRequest $request, IssueReceipt $issue): RedirectResponse
    {
        $receipt = $issue($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Receipt N.º :number issued.', ['number' => ReceiptNumber::format($receipt->number)])]);

        return redirect()->route('treasury.receipts.show', $receipt);
    }

    public function cancel(Request $request, Receipt $receipt, VoidReceipt $void): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $void($receipt, $data['reason']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Receipt N.º :number voided.', ['number' => ReceiptNumber::format($receipt->number)])]);

        return back();
    }

    public function settings(Request $request, SaveReceiptSettings $save): RedirectResponse
    {
        $data = $request->validate(['start_number' => ['required', 'integer', 'min:1', 'max:'.SaveReceiptSettings::MAX]]);

        $save((int) $data['start_number']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('The starting number of the book was saved.')]);

        return back();
    }
}
