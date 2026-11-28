<?php

namespace App\Modules\Integrity\Application\Seals;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * For a model that implements Sealed: records the seal of its row each time it is saved (restoring
 * included) or deleted (soft). It runs inside the Action's transaction, so the row and its seal are committed
 * or rolled back together. With no integrity key configured it does nothing.
 *
 * @mixin Model
 */
trait SealsRows
{
    public static function bootSealsRows(): void
    {
        $seal = function (Model&Sealed $model): void {
            $recorder = RecordSeal::fromConfig();

            if (! $recorder->enabled()) {
                return;
            }

            DB::table('row_seals')->insert([
                'table_name' => $model->getTable(),
                'row_id' => (string) $model->getKey(),
                'seal' => $recorder->of($model),
            ]);
        };

        // Restoring a soft-deleted row saves it, so `saved` covers it; a soft delete only updates, hence `deleted`.
        static::saved($seal);
        static::deleted($seal);
    }
}
