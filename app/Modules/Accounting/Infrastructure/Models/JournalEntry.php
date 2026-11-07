<?php

namespace App\Modules\Accounting\Infrastructure\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A journal entry. Insert-only; the database checks that it balances in every currency.
 *
 * @property string $id UUIDv7
 * @property Carbon $entry_date
 * @property string $month
 * @property string $description
 * @property string $origin_type
 * @property string $origin_id
 * @property string $origin_purpose
 * @property string|null $reversal_of
 * @property string|null $reversal_reason
 * @property int $lines_count
 * @property string|null $created_by
 * @property Carbon $created_at
 * @property-read Collection<int, JournalEntryLine> $lines
 * @property-read JournalEntry|null $original
 * @property-read JournalEntry|null $reversal
 * @property-read User|null $author
 * @property-read JournalEntrySupport|null $support
 */
#[Fillable([
    'entry_date', 'month', 'description', 'origin_type', 'origin_id', 'origin_purpose',
    'reversal_of', 'reversal_reason', 'lines_count', 'created_by',
])]
class JournalEntry extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /**
     * @return HasMany<JournalEntryLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class)->orderBy('line_number');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of');
    }

    /**
     * @return HasOne<self, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of');
    }

    /**
     * @return HasOne<JournalEntrySupport, $this>
     */
    public function support(): HasOne
    {
        return $this->hasOne(JournalEntrySupport::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'lines_count' => 'integer',
        ];
    }
}
