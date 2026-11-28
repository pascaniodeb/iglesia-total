<?php

namespace App\Modules\Treasury\Infrastructure\Models;

use App\Modules\Integrity\Application\Seals\Sealed;
use App\Modules\Integrity\Application\Seals\SealsRows;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A closed week. Insert-only; the database checks the snapshot against the offerings and
 * locks the week's offerings from then on.
 *
 * @property string $id UUIDv7
 * @property int|null $legacy_id
 * @property string $week_key
 * @property Carbon $week_start
 * @property Carbon $week_end
 * @property int $offerings_count
 * @property string $total_ves
 * @property string $total_usd
 * @property string $total_cop
 * @property Carbon $closed_at
 * @property string|null $closed_by
 */
class WeeklyClosure extends Model implements Sealed
{
    use HasUuids;
    use SealsRows;

    public $timestamps = false;

    public static function isClosed(string $weekKey): bool
    {
        return self::query()->where('week_key', $weekKey)->exists();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'legacy_id' => 'integer',
            'week_start' => 'date',
            'week_end' => 'date',
            'offerings_count' => 'integer',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Every column enters the seal: none changes by a path that no Action records.
     *
     * @return list<string>
     */
    public static function unsealedColumns(): array
    {
        return [];
    }
}
