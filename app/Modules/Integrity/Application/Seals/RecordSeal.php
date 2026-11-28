<?php

namespace App\Modules\Integrity\Application\Seals;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The seal of a row: HMAC-SHA256 with the integrity key, over the row's canonical form.
 *
 * The canonical form is produced by PostgreSQL (`to_jsonb` orders the keys and fixes the format of
 * every type), when sealing and when verifying: it does not depend on how PHP casts values. The key
 * stops whoever only has database access from forging a valid seal for a changed row.
 */
final readonly class RecordSeal
{
    private const int MIN_KEY_LENGTH = 32;

    public function __construct(private ?string $key) {}

    public static function fromConfig(): self
    {
        $key = config('integrity.seal_key');

        return new self(is_string($key) && $key !== '' ? $key : null);
    }

    /**
     * Whether rows are being sealed at all: without a key nothing is sealed or verified.
     */
    public function enabled(): bool
    {
        return $this->key !== null;
    }

    /**
     * The seal of the model's row as it is now in the database (call inside the transaction).
     */
    public function of(Model&Sealed $model): string
    {
        $row = DB::selectOne(
            sprintf('SELECT %s AS payload FROM %s t WHERE t.%s = ?', $this->payloadSql(), $model->getTable(), $model->getKeyName()),
            [$this->excluded($model::unsealedColumns()), $model->getKey()],
        );

        if ($row === null) {
            throw new LogicException('A row that does not exist is not sealed: '.$model->getTable().' '.$model->getKey());
        }

        return $this->sign($model->getTable(), (string) $row->payload);
    }

    public function sign(string $table, string $payload): string
    {
        return hash_hmac('sha256', $table."\n".$payload, $this->key());
    }

    /**
     * SQL expression for the canonical form of the row `t`; its one binding is the excluded columns.
     */
    public function payloadSql(): string
    {
        return '(to_jsonb(t) - ?::text[])::text';
    }

    /**
     * @param  list<string>  $columns
     */
    public function excluded(array $columns): string
    {
        return '{'.implode(',', $columns).'}';
    }

    private function key(): string
    {
        if ($this->key === null || strlen($this->key) < self::MIN_KEY_LENGTH) {
            throw new LogicException('INTEGRITY_SEAL_KEY is missing or shorter than '.self::MIN_KEY_LENGTH.' characters: without it rows are neither sealed nor verified.');
        }

        return $this->key;
    }
}
