<?php

namespace App\Modules\Accounting\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A closed accounting month. Insert-only; months close in order.
 *
 * @property string $id
 * @property string $month
 * @property Carbon $closed_at
 * @property string|null $closed_by
 */
class AccountingPeriod extends Model
{
    use HasUuids;

    public $timestamps = false;

    public static function isClosed(string $month): bool
    {
        return self::query()->where('month', $month)->exists();
    }

    public static function lastClosed(): ?string
    {
        $month = self::query()->max('month');

        return $month === null ? null : (string) $month;
    }

    /**
     * @return HasMany<AccountingPeriodBalance, $this>
     */
    public function balances(): HasMany
    {
        return $this->hasMany(AccountingPeriodBalance::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['closed_at' => 'immutable_datetime'];
    }
}
