<?php

namespace App\Console\Commands;

use App\Modules\Integrity\Infrastructure\Models\LedgerBlock;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * The cut-over: every import of the old system, in the only order that works, then the
 * cent-exact reconciliation. Each step is idempotent, so a failed run is fixed and run again from
 * the top. It stops at the first step that fails and it fails unless the reconciliation shows zero
 * differences: nothing is "migrated" until it does.
 *
 * --dry-run only rehearses the steps that read the old system alone; the rest need the earlier
 * ones written, so they are listed and must be rehearsed on a scratch database.
 */
#[Signature('migrate:legacy
    {church-code : Code of the congregation printed on its reports (for the genesis block)}
    {--first-period=2025-W01 : First week the new chain seals}
    {--connection=legacy : Database connection of the old system}
    {--legacy-storage= : Legacy storage root with the church logos}
    {--dry-run : Rehearse the steps that need nothing written before them, and list the rest}')]
#[Description('Run the whole migration from the old system, then reconcile it to the cent')]
class MigrateLegacy extends Command
{
    public function handle(): int
    {
        $connection = ['--connection' => (string) $this->option('connection')];
        $dry = (bool) $this->option('dry-run');
        $storage = $this->option('legacy-storage') === null ? [] : ['--legacy-storage' => (string) $this->option('legacy-storage')];

        $independent = [
            ['members:import-legacy', []],
            ['users:import-legacy', []],
            ['church:import-legacy-settings', $storage],
            ['accounting:import-legacy-accounts', []],
            ['treasury:import-legacy-categories', []],
            ['ledger:import-legacy', []],
        ];

        foreach ($independent as [$command, $extra]) {
            if (! $this->step($command, [...$connection, ...$extra, ...($dry ? ['--dry-run' => true] : [])])) {
                return self::FAILURE;
            }
        }

        $dependent = ['treasury:import-legacy-offerings', 'treasury:import-legacy-closures', 'treasury:import-legacy-receipt-settings', 'accounting:import-legacy-books'];

        if ($dry) {
            $this->components->info('Dry run: these need the steps above written first, rehearse them on a scratch database: genesis, '.implode(', ', $dependent).', reconcile:legacy.');

            return self::SUCCESS;
        }

        if (LedgerBlock::query()->doesntExist()
            && ! $this->step('ledger:genesis', ['church-code' => (string) $this->argument('church-code'), 'first-period' => (string) $this->option('first-period'), '--continue-legacy' => true])) {
            return self::FAILURE;
        }

        foreach ($dependent as $command) {
            if (! $this->step($command, $connection)) {
                return self::FAILURE;
            }
        }

        if (! $this->step('reconcile:legacy', $connection)) {
            $this->components->error('The migration ran but the books do NOT match the old system: do not cut over.');

            return self::FAILURE;
        }

        $this->components->info('Migrated: every figure matches the old system to the cent.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function step(string $command, array $arguments): bool
    {
        $this->components->info("→ {$command}");

        $reason = '';

        try {
            if ($this->call($command, $arguments) === self::SUCCESS) {
                return true;
            }
        } catch (Throwable $exception) {
            $reason = ' ('.$exception->getMessage().')';
        }

        $this->components->error("{$command} failed{$reason}: fix it and run migrate:legacy again (every step is idempotent).");

        return false;
    }
}
