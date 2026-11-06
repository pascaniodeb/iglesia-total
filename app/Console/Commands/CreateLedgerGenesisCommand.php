<?php

namespace App\Console\Commands;

use App\Modules\Integrity\Application\Actions\CreateLedgerGenesis;
use App\Modules\Integrity\Domain\Ledger\LedgerException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Opens the sealed ledger. At the cut-over it is given the head of the old system's chain
 * (--continues-height and --continues-hash) so the new chain declares which one it continues.
 */
#[Signature('ledger:genesis
    {church-code : Code of the congregation printed on its reports}
    {first-period : First period the chain seals (e.g. 2025-W01)}
    {--continues-height= : Height of the head of the chain this one continues}
    {--continues-hash= : Hash of the head of the chain this one continues}')]
#[Description('Create the genesis block of the sealed ledger')]
class CreateLedgerGenesisCommand extends Command
{
    public function handle(CreateLedgerGenesis $createGenesis): int
    {
        $height = $this->option('continues-height');
        $hash = $this->option('continues-hash');

        if (($height === null) !== ($hash === null)) {
            $this->components->error('Give both --continues-height and --continues-hash, or neither.');

            return self::FAILURE;
        }

        if ($hash !== null && (preg_match('/^[0-9a-f]{64}$/', (string) $hash) !== 1 || ! ctype_digit((string) $height))) {
            $this->components->error('The continued head needs a whole height and a 64-character lowercase hex hash.');

            return self::FAILURE;
        }

        try {
            $block = $createGenesis(
                (string) $this->argument('church-code'),
                (string) $this->argument('first-period'),
                $hash === null ? null : ['height' => (int) $height, 'hash' => (string) $hash],
            );
        } catch (LedgerException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Sealed ledger opened.');
        $this->components->twoColumnDetail('Genesis hash', $block->hash);

        return self::SUCCESS;
    }
}
