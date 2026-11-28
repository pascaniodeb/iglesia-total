<?php

namespace App\Modules\Integrity\Application\Seals;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Seals every row of the sealed models that has no seal yet (the rows that were there before sealing
 * was switched on, or that came in outside the Actions, like an import). It never touches a row that
 * has a seal: a changed row is a finding to investigate, not something to be sealed over.
 *
 * Sealing a row vouches for it from now on; it cannot vouch for what happened before.
 */
final readonly class SealUnsealedRows
{
    public function __construct(private SealedModels $models, private RecordSeal $seal, private VerifyRowSeals $verify) {}

    /**
     * @return array{sealed: int, by_table: array<string, int>}
     */
    public function __invoke(): array
    {
        if (! $this->seal->enabled()) {
            throw new LogicException('INTEGRITY_SEAL_KEY is not set: rows cannot be sealed.');
        }

        $total = 0;
        $byTable = [];

        DB::transaction(function () use (&$total, &$byTable): void {
            foreach ($this->models->all() as $model) {
                $table = (new $model)->getTable();
                $count = 0;

                foreach ($this->verify->rows($model) as $row) {
                    if ($row['seal'] !== null) {
                        continue;
                    }

                    DB::table('row_seals')->insert(['table_name' => $table, 'row_id' => $row['id'], 'seal' => $this->seal->sign($table, $row['payload'])]);
                    $count++;
                }

                $byTable[$table] = $count;
                $total += $count;
            }
        });

        return ['sealed' => $total, 'by_table' => $byTable];
    }
}
