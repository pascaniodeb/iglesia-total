<?php

namespace App\Modules\Integrity\Application\Seals;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes the seal of every row of every sealed model and compares it with the latest recorded:
 *
 * - changed: the row no longer says what it said when it was sealed (changed outside the Actions).
 * - unsealed: a row with no seal (created before sealing was switched on, or behind the Actions);
 *   `integrity:seal-rows` seals them, from then on they are protected.
 * - gone: a seal whose row no longer exists (erased outside the Actions).
 *
 * Without an integrity key nothing is sealed, so nothing is verified.
 */
final readonly class VerifyRowSeals
{
    /** Rows named per finding kind; the counts say how many there are. */
    private const int LISTED = 25;

    public function __construct(private SealedModels $models, private RecordSeal $seal) {}

    /**
     * @return array{enabled: bool, checked: int, changed: list<string>, unsealed: list<string>, gone: list<string>, counts: array{changed: int, unsealed: int, gone: int}}
     */
    public function __invoke(): array
    {
        $found = ['changed' => [], 'unsealed' => [], 'gone' => []];
        $counts = ['changed' => 0, 'unsealed' => 0, 'gone' => 0];
        $checked = 0;

        if ($this->seal->enabled()) {
            $note = function (string $kind, string $label) use (&$found, &$counts): void {
                $counts[$kind]++;

                if (count($found[$kind]) < self::LISTED) {
                    $found[$kind][] = $label;
                }
            };

            foreach ($this->models->all() as $model) {
                $table = (new $model)->getTable();

                foreach ($this->rows($model) as $row) {
                    $checked++;

                    if ($row['seal'] === null) {
                        $note('unsealed', "{$table}:{$row['id']}");
                    } elseif (! hash_equals($row['seal'], $this->seal->sign($table, $row['payload']))) {
                        $note('changed', "{$table}:{$row['id']}");
                    }
                }

                foreach ($this->gone($model) as $id) {
                    $note('gone', "{$table}:{$id}");
                }
            }
        }

        return ['enabled' => $this->seal->enabled(), 'checked' => $checked, 'changed' => $found['changed'], 'unsealed' => $found['unsealed'], 'gone' => $found['gone'], 'counts' => ['changed' => $counts['changed'], 'unsealed' => $counts['unsealed'], 'gone' => $counts['gone']]];
    }

    /**
     * Every row of the model with its canonical form and its latest seal (null when it has none).
     *
     * @param  class-string<Model&Sealed>  $model
     * @return \Generator<int, array{id: string, payload: string, seal: string|null}>
     */
    public function rows(string $model): \Generator
    {
        $instance = new $model;
        $sql = sprintf(
            'SELECT t.%1$s::text AS id, %2$s AS payload, s.seal FROM %3$s t LEFT JOIN LATERAL (SELECT rs.seal FROM row_seals rs WHERE rs.table_name = ? AND rs.row_id = t.%1$s::text ORDER BY rs.seq DESC LIMIT 1) s ON true',
            $instance->getKeyName(),
            $this->seal->payloadSql(),
            $instance->getTable(),
        );

        foreach (DB::cursor($sql, [$this->seal->excluded($model::unsealedColumns()), $instance->getTable()]) as $row) {
            $row = (array) $row;
            yield ['id' => (string) $row['id'], 'payload' => (string) $row['payload'], 'seal' => $row['seal'] === null ? null : trim((string) $row['seal'])];
        }
    }

    /**
     * Ids that have a seal but no longer have a row.
     *
     * @param  class-string<Model&Sealed>  $model
     * @return list<string>
     */
    private function gone(string $model): array
    {
        $instance = new $model;

        return array_values(array_map(
            fn (object $row): string => (string) ((array) $row)['row_id'],
            DB::select(sprintf(
                'SELECT DISTINCT rs.row_id FROM row_seals rs WHERE rs.table_name = ? AND NOT EXISTS (SELECT 1 FROM %s t WHERE t.%s::text = rs.row_id) ORDER BY rs.row_id',
                $instance->getTable(),
                $instance->getKeyName(),
            ), [$instance->getTable()]),
        ));
    }
}
