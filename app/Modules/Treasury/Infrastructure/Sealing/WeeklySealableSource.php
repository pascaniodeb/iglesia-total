<?php

namespace App\Modules\Treasury\Infrastructure\Sealing;

use App\Modules\Integrity\Domain\Ledger\Canonical;
use App\Modules\Integrity\Domain\Ledger\SealablePayload;
use App\Modules\Integrity\Domain\Ledger\SealableSource;
use App\Modules\Treasury\Infrastructure\Models\WeeklyClosure;
use Illuminate\Support\Facades\DB;

/**
 * What a closed week seals: the closure's snapshot and the week's live offerings, one Merkle
 * leaf per offering, ordered by id (the only order that does not change if someone corrects a
 * date). Rebuilt on every verification: if an offering of a sealed week changes behind the
 * database guards, the payload or the Merkle root no longer match and the ledger reports drift.
 *
 * Amounts travel as the decimal text PostgreSQL returns; nothing in the payload depends on when
 * it is built.
 */
final class WeeklySealableSource implements SealableSource
{
    public function kind(): string
    {
        return 'weekly';
    }

    public function build(string $periodKey): ?SealablePayload
    {
        $closure = WeeklyClosure::query()->where('week_key', $periodKey)->first();

        if ($closure === null) {
            return null;
        }

        $offerings = DB::select(<<<'SQL'
            SELECT id::text AS id, offering_date::text AS date, member_id::text AS member_id,
                   offering_category_id::text AS category_id,
                   amount_ves::text AS ves, amount_usd::text AS usd, amount_cop::text AS cop
            FROM offerings
            WHERE week_key = ? AND deleted_at IS NULL
            ORDER BY id
        SQL, [$periodKey]);

        $live = DB::selectOne(<<<'SQL'
            SELECT coalesce(sum(amount_ves), 0)::numeric(18,2)::text AS ves,
                   coalesce(sum(amount_usd), 0)::numeric(18,2)::text AS usd,
                   coalesce(sum(amount_cop), 0)::numeric(18,2)::text AS cop
            FROM offerings
            WHERE week_key = ? AND deleted_at IS NULL
        SQL, [$periodKey]);

        $leaves = [];

        foreach ($offerings as $offering) {
            $row = (array) $offering;
            $leaves[] = Canonical::hash([
                'id' => $row['id'],
                'date' => $row['date'],
                'memberId' => $row['member_id'],
                'categoryId' => $row['category_id'],
                'ves' => $row['ves'],
                'usd' => $row['usd'],
                'cop' => $row['cop'],
            ]);
        }

        return new SealablePayload(
            [
                'kind' => 'weekly',
                'weekKey' => $closure->week_key,
                'weekStart' => $closure->week_start->toDateString(),
                'weekEnd' => $closure->week_end->toDateString(),
                'offeringsCount' => count($offerings),
                'totals' => ['ves' => $live->ves, 'usd' => $live->usd, 'cop' => $live->cop],
                'closure' => [
                    'offeringsCount' => $closure->offerings_count,
                    'totals' => ['ves' => $closure->total_ves, 'usd' => $closure->total_usd, 'cop' => $closure->total_cop],
                ],
            ],
            $leaves,
        );
    }

    public function closedPeriods(): array
    {
        return array_values(array_map(strval(...), WeeklyClosure::query()->orderBy('week_start')->pluck('week_key')->all()));
    }
}
