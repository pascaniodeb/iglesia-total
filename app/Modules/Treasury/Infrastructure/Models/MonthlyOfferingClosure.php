<?php

namespace App\Modules\Treasury\Infrastructure\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A month whose offerings were closed and posted to the journal. Insert-only; the database
 * checks the weeks, the snapshot and the posted entry.
 *
 * @property string $id UUIDv7
 * @property int|null $legacy_id
 * @property string $month
 * @property int $offerings_count
 * @property string $total_ves
 * @property string $total_usd
 * @property string $total_cop
 * @property string|null $journal_entry_id
 * @property Carbon $closed_at
 * @property string|null $closed_by
 * @property-read Collection<int, MonthlyOfferingClosureLine> $lines
 */
class MonthlyOfferingClosure extends Model
{
    use HasUuids;

    public $timestamps = false;

    public static function isClosed(string $month): bool
    {
        return self::query()->where('month', $month)->exists();
    }

    /**
     * @return HasMany<MonthlyOfferingClosureLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(MonthlyOfferingClosureLine::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'legacy_id' => 'integer',
            'offerings_count' => 'integer',
            'closed_at' => 'immutable_datetime',
        ];
    }
}
