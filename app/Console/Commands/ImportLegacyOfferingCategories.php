<?php

namespace App\Console\Commands;

use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Copies the offering categories from the old system. Each one keeps its income account,
 * found by the account's legacy id: run accounting:import-legacy-accounts first. Idempotent by
 * legacy_id; nothing is written if any category points to an account that is missing or is
 * not an income account.
 */
#[Signature('treasury:import-legacy-categories {--connection=legacy : Database connection of the old system} {--dry-run : Report what would be imported without writing}')]
#[Description('Import the offering categories from the old system database')]
class ImportLegacyOfferingCategories extends Command
{
    public function handle(ChartOfAccounts $chart, ActivityLog $activityLog): int
    {
        $rows = DB::connection((string) $this->option('connection'))->table('offering_categories')->orderBy('id')->get();
        $this->components->info("Legacy source: {$rows->count()} offering categories.");

        /** @var array<int, string> $accountIds legacy account id => account id */
        $accountIds = DB::table('accounting_accounts')->whereNotNull('legacy_id')->pluck('id', 'legacy_id')->all();

        foreach ($rows as $row) {
            $account = isset($accountIds[(int) $row->account_code_id]) ? $chart->find($accountIds[(int) $row->account_code_id]) : null;

            if ($account === null || $account->type !== AccountType::Income) {
                $this->components->error("Category {$row->id} ({$row->name}) points to an account that is missing or is not income; run accounting:import-legacy-accounts first.");

                return self::FAILURE;
            }
        }

        if ($this->option('dry-run')) {
            $this->components->warn('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($rows, $accountIds, $activityLog, &$created, &$updated): void {
            foreach ($rows as $row) {
                $category = OfferingCategory::query()->where('legacy_id', $row->id)->first();

                if ($category === null) {
                    $category = new OfferingCategory;
                    $category->setAttribute($category->getKeyName(), (string) Str::uuid7(Carbon::parse($row->created_at)));
                    $created++;
                } else {
                    $updated++;
                }

                $category->forceFill([
                    'legacy_id' => (int) $row->id,
                    'name' => $row->name,
                    'account_id' => $accountIds[(int) $row->account_code_id],
                    'is_active' => (bool) $row->is_active,
                ])->save();
            }

            $activityLog->system(
                'offering_categories.imported_legacy',
                __('Imported the offering categories of the old system: :created created, :updated updated.', ['created' => $created, 'updated' => $updated]),
                details: ['created' => $created, 'updated' => $updated],
            );
        });

        $this->components->twoColumnDetail('Categories', "{$created} created, {$updated} updated");

        return self::SUCCESS;
    }
}
