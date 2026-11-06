<?php

namespace App\Console\Commands;

use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemFunction;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Integrity\Application\ActivityLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Copies the chart of accounts (account_codes) from the old system.
 *
 * - Idempotent: matched by legacy_id, then by code. Re-running refreshes names and the
 *   active flag; codes and types never change.
 * - I-001 SALDO ANTERIOR is not created: it is the system opening balance account, which
 *   already exists; it only gets its legacy_id.
 * - Every other code must be I-xxx (income) or E-xxx (expense) and its legacy type must agree,
 *   or nothing is written.
 */
#[Signature('accounting:import-legacy-accounts {--connection=legacy : Database connection of the old system} {--dry-run : Report what would be imported without writing}')]
#[Description('Import the chart of accounts from the old system database')]
class ImportLegacyAccountingAccounts extends Command
{
    public function handle(ActivityLog $activityLog): int
    {
        $rows = DB::connection((string) $this->option('connection'))->table('account_codes')->orderBy('code')->get();
        $this->components->info("Legacy source: {$rows->count()} account codes.");

        foreach ($rows as $row) {
            $type = AccountType::forCode((string) $row->code);

            if ($row->code !== 'I-001' && ($type === null || $type->value !== $row->type)) {
                $this->components->error("Code {$row->code} ({$row->type}) does not fit the chart: nothing was imported.");

                return self::FAILURE;
            }
        }

        if ($this->option('dry-run')) {
            $this->components->warn('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($rows, $activityLog, &$created, &$updated): void {
            foreach ($rows as $row) {
                $account = AccountingAccount::query()->where('legacy_id', $row->id)->first()
                    ?? AccountingAccount::query()->where('code', $row->code)->first();

                if ($account === null) {
                    $account = new AccountingAccount;
                    $account->setAttribute($account->getKeyName(), (string) Str::uuid7(Carbon::parse($row->created_at)));
                    $account->forceFill(['code' => $row->code, 'type' => AccountType::forCode((string) $row->code)]);
                    $created++;
                } else {
                    $updated++;
                }

                $isOpening = $account->system_function === SystemFunction::OpeningBalance;

                $account->forceFill([
                    'legacy_id' => (int) $row->id,
                    'name' => $row->name,
                    'is_active' => $isOpening || (bool) $row->is_active,
                ])->save();
            }

            $activityLog->system(
                'accounting_accounts.imported_legacy',
                __('Imported the chart of accounts of the old system: :created created, :updated updated.', ['created' => $created, 'updated' => $updated]),
                details: ['created' => $created, 'updated' => $updated],
            );
        });

        $this->components->twoColumnDetail('Accounts', "{$created} created, {$updated} updated");

        return self::SUCCESS;
    }
}
